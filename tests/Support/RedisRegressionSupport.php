<?php

declare(strict_types=1);

use HongXunPan\DB\Redis\Contract\RedisClientFactory;
use HongXunPan\DB\Redis\Redis;
use HongXunPan\SimpleEvent\Config\DriverConfig;
use HongXunPan\SimpleEvent\Driver\Redis\RedisStreamDriver;
use HongXunPan\SimpleEvent\Event;
use HongXunPan\SimpleEvent\Execution\Failure;
use HongXunPan\SimpleEvent\Listener\ShouldQueue;
use HongXunPan\SimpleEvent\Message\EventMessage;
use HongXunPan\SimpleEvent\Serialization\Serializer;

final readonly class RedisRegressionOccurred implements Event
{
    public function __construct(public string $name)
    {
    }
}

final readonly class RedisRegressionListener implements ShouldQueue
{
    public function handle(RedisRegressionOccurred $event): void
    {
    }
}

final class RedisRegressionFixedSerializer implements Serializer
{
    public function serialize(EventMessage $message): string
    {
        return '{"message_version":2}';
    }

    public function deserialize(string $payload): EventMessage
    {
        throw new LogicException('当前测试不调用反序列化');
    }
}

final class RedisRegressionThrowingSerializer implements Serializer
{
    public function serialize(EventMessage $message): string
    {
        throw new RuntimeException('测试序列化失败');
    }

    public function deserialize(string $payload): EventMessage
    {
        throw new LogicException('当前测试不调用反序列化');
    }
}

final class RedisRegressionClient
{
    /** @var list<array{method: string, arguments: array<mixed>}> */
    public array $calls = [];

    public string|false $xAddResult = '1-0';
    public int|false $xAckResult = 1;

    /** @var array<mixed>|false */
    public array|false $autoClaimResult = ['0-0', []];

    /** @var array<mixed>|false */
    public array|false $readGroupResult = [];

    public ?Throwable $xAddFailure = null;
    public ?Throwable $xAckFailure = null;
    public ?Throwable $xDelFailure = null;

    public function xAdd(
        string $key,
        string $id,
        array $fields,
        ?int $maxLength = null,
        bool $approximate = false,
    ): string|false {
        $this->record(__FUNCTION__, func_get_args());
        if ($this->xAddFailure !== null) {
            throw $this->xAddFailure;
        }

        return $this->xAddResult;
    }

    public function xGroup(
        string $operation,
        string $key,
        string $group,
        string $id,
        bool $createStream,
    ): bool {
        $this->record(__FUNCTION__, func_get_args());

        return true;
    }

    public function xAutoClaim(
        string $key,
        string $group,
        string $consumer,
        int $minIdleTime,
        string $start,
        int $count,
        bool $justId,
    ): array|false {
        $this->record(__FUNCTION__, func_get_args());

        return $this->autoClaimResult;
    }

    public function xReadGroup(
        string $group,
        string $consumer,
        array $streams,
        int $count,
        int $block,
    ): array|false {
        $this->record(__FUNCTION__, func_get_args());

        return $this->readGroupResult;
    }

    public function xAck(string $key, string $group, array $ids): int|false
    {
        $this->record(__FUNCTION__, func_get_args());
        if ($this->xAckFailure !== null) {
            throw $this->xAckFailure;
        }

        return $this->xAckResult;
    }

    public function xDel(string $key, array $ids): int|false
    {
        $this->record(__FUNCTION__, func_get_args());
        if ($this->xDelFailure !== null) {
            throw $this->xDelFailure;
        }

        return 1;
    }

    /** @return list<array{method: string, arguments: array<mixed>}> */
    public function callsFor(string $method): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['method'] === $method,
        ));
    }

    /** @param array<mixed> $arguments */
    private function record(string $method, array $arguments): void
    {
        $this->calls[] = compact('method', 'arguments');
    }
}

function makeRedisRegressionManager(RedisRegressionClient $client): Redis
{
    $factory = new class ($client) implements RedisClientFactory {
        public function __construct(private readonly RedisRegressionClient $client)
        {
        }

        public function create(array $config): RedisRegressionClient
        {
            return $this->client;
        }
    };

    return Redis::withClientFactory(
        ['default' => ['host' => 'redis-regression']],
        $factory,
    );
}

/** @param array<string, mixed> $overrides */
function makeRedisRegressionDriverConfig(array $overrides = []): DriverConfig
{
    return DriverConfig::fromArray([
        'class' => RedisStreamDriver::class,
        'options' => array_replace([
            'connection' => 'default',
            'stream' => 'simple-event:regression',
            'group' => 'simple-event-regression',
            'failed_stream' => 'simple-event:regression:failed',
            'block_ms' => 1,
            'batch_size' => 10,
            'claim_idle_ms' => 1,
            'failed_max_length' => 100,
        ], $overrides),
    ]);
}

function makeRedisRegressionMessage(): EventMessage
{
    return new EventMessage(
        eventId: 'event-redis-regression',
        createdAt: new DateTimeImmutable(),
        event: new RedisRegressionOccurred('redis'),
        listeners: [RedisRegressionListener::class],
        traceId: 'trace-redis-regression',
    );
}

function makeRedisRegressionFailure(string $messageId = '1-0'): Failure
{
    return new Failure(
        messageId: $messageId,
        eventId: 'event-redis-regression',
        eventClass: RedisRegressionOccurred::class,
        eventVersion: 1,
        traceId: 'trace-redis-regression',
        messageCreatedAt: new DateTimeImmutable(),
        listeners: [],
        errorClass: RuntimeException::class,
        errorMessage: '消费失败',
        failedAt: new DateTimeImmutable(),
    );
}
