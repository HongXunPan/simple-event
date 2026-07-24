<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Driver\Redis;

use HongXunPan\DB\Redis\Redis;
use HongXunPan\DB\Redis\RedisConnection;
use HongXunPan\SimpleEvent\Config\DriverConfig;
use HongXunPan\SimpleEvent\Consumer\Consumer;
use HongXunPan\SimpleEvent\Consumer\ReceivedMessage;
use HongXunPan\SimpleEvent\Exception\EventConsumeException;
use HongXunPan\SimpleEvent\Execution\Failure;
use JsonException;
use RedisException;
use Throwable;

final class RedisStreamConsumer implements Consumer
{
    private const string MESSAGE_FIELD = 'message';
    private const string FAILURE_FIELD = 'failure';

    private readonly RedisStreamConfig $config;
    private readonly string $consumer;
    private ?RedisConnection $connection = null;
    private bool $groupReady = false;
    private string $claimCursor = '0-0';

    public function __construct(
        DriverConfig $config,
        private readonly Redis $redis,
        ?string $consumer = null,
    ) {
        $this->config = RedisStreamConfig::fromDriverConfig($config);
        $this->consumer = $consumer ?: $this->createConsumerName();
    }

    public function receive(): iterable
    {
        $this->ensureGroup();

        $messages = $this->messages($this->claimPending());
        $remaining = $this->config->batchSize - count($messages);
        if ($remaining > 0) {
            array_push($messages, ...$this->messages($this->readNew($remaining)));
        }

        return $messages;
    }

    public function acknowledge(ReceivedMessage $message): void
    {
        try {
            $acknowledged = $this->connection()->xAck(
                $this->config->stream,
                $this->config->group,
                [$message->id],
            );
        } catch (Throwable $throwable) {
            throw new EventConsumeException(
                "Redis 消息 ACK 失败：{$message->id}",
                previous: $throwable,
            );
        }
        if ($acknowledged !== 1) {
            throw new EventConsumeException("Redis 消息 ACK 数量异常：{$message->id}");
        }

        try {
            $this->connection()->xDel($this->config->stream, [$message->id]);
        } catch (Throwable) {
            // XACK 已形成消费终态，XDEL 失败不得伪装成可重放的消费失败。
        }
    }

    public function fail(ReceivedMessage $message, Failure $failure): void
    {
        $failurePayload = $this->serializeFailure($failure);

        try {
            $failedId = $this->connection()->xAdd(
                $this->config->failedStream,
                '*',
                [self::MESSAGE_FIELD => $message->body, self::FAILURE_FIELD => $failurePayload],
                $this->config->failedMaxLength,
                true,
            );
        } catch (Throwable $throwable) {
            throw new EventConsumeException(
                '失败消息写入 failed stream 失败',
                previous: $throwable,
            );
        }
        if (!is_string($failedId) || preg_match('/^\d+-\d+$/D', $failedId) !== 1) {
            throw new EventConsumeException('failed stream 未返回有效消息 ID');
        }

        $this->acknowledge($message);
    }

    private function serializeFailure(Failure $failure): string
    {
        try {
            $payload = $failure->toArray();
            $payload['consumer'] = $this->consumer;

            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE,
            );
        } catch (JsonException $exception) {
            throw new EventConsumeException(
                '失败消息摘要编码失败',
                previous: $exception,
            );
        }
    }

    private function ensureGroup(): void
    {
        if ($this->groupReady) {
            return;
        }

        try {
            $this->connection()->xGroup(
                'CREATE',
                $this->config->stream,
                $this->config->group,
                '0',
                true,
            );
        } catch (RedisException $exception) {
            if (!str_contains($exception->getMessage(), 'BUSYGROUP')) {
                throw new EventConsumeException(
                    'Redis Consumer Group 创建失败',
                    previous: $exception,
                );
            }
        } catch (Throwable $throwable) {
            throw new EventConsumeException(
                'Redis Consumer Group 初始化失败',
                previous: $throwable,
            );
        }

        $this->groupReady = true;
    }

    /** @return array<string, array<string, string>> */
    private function claimPending(): array
    {
        try {
            $claimed = $this->connection()->xAutoClaim(
                $this->config->stream,
                $this->config->group,
                $this->consumer,
                $this->config->claimIdleMs,
                $this->claimCursor,
                $this->config->batchSize,
                false,
            );
        } catch (Throwable $throwable) {
            throw new EventConsumeException(
                'Redis pending 消息回收失败',
                previous: $throwable,
            );
        }

        if (!is_array($claimed)) {
            return [];
        }

        $this->claimCursor = is_string($claimed[0] ?? null) ? $claimed[0] : '0-0';

        return is_array($claimed[1] ?? null) ? $claimed[1] : [];
    }

    /** @return array<string, array<string, string>> */
    private function readNew(int $count): array
    {
        try {
            $streams = $this->connection()->xReadGroup(
                $this->config->group,
                $this->consumer,
                [$this->config->stream => '>'],
                $count,
                $this->config->blockMs,
            );
        } catch (Throwable $throwable) {
            throw new EventConsumeException(
                'Redis 新消息读取失败',
                previous: $throwable,
            );
        }

        if (!is_array($streams) || $streams === []) {
            return [];
        }
        if (count($streams) !== 1) {
            throw new EventConsumeException('Redis 新消息返回的 Stream 数量异常');
        }

        $entries = array_values($streams)[0];

        return is_array($entries) ? $entries : [];
    }

    /**
     * @param array<string, array<string, string>> $entries
     * @return list<ReceivedMessage>
     */
    private function messages(array $entries): array
    {
        $messages = [];
        foreach ($entries as $streamId => $fields) {
            $body = $fields[self::MESSAGE_FIELD] ?? '';
            $messages[] = new ReceivedMessage(
                $streamId,
                is_string($body) ? $body : '',
            );
        }

        return $messages;
    }

    private function connection(): RedisConnection
    {
        return $this->connection ??= $this->redis->getConnection($this->config->connection);
    }

    private function createConsumerName(): string
    {
        $host = preg_replace('/[^a-zA-Z0-9_-]+/', '-', gethostname() ?: 'worker') ?: 'worker';

        return sprintf('%s-%d-%s', $host, getmypid() ?: 0, bin2hex(random_bytes(4)));
    }
}
