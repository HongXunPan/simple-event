<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Driver\Redis;

use HongXunPan\DB\Redis\Redis;
use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Module\ModuleConfig;
use HongXunPan\SimpleEvent\Config\DriverConfig;
use HongXunPan\SimpleEvent\Consumer\Consumer;
use HongXunPan\SimpleEvent\Driver\Driver;
use HongXunPan\SimpleEvent\Exception\EventConfigException;
use HongXunPan\SimpleEvent\Exception\EventPublishException;
use HongXunPan\SimpleEvent\Message\EventMessage;
use HongXunPan\SimpleEvent\Serialization\Serializer;
use HongXunPan\SimpleRedis\Config\RedisConfig;
use HongXunPan\SimpleRedis\RedisModule;
use Throwable;

final readonly class RedisStreamDriver implements Driver
{
    private const string MESSAGE_FIELD = 'message';

    private RedisStreamConfig $config;

    public function __construct(
        DriverConfig $config,
        private Serializer $serializer,
        private Redis $redis,
    ) {
        $this->config = RedisStreamConfig::fromDriverConfig($config);
    }

    public static function validateConfig(DriverConfig $config, Application $app): void
    {
        $options = RedisStreamConfig::fromDriverConfig($config);
        if (!class_exists(RedisModule::class) || !class_exists(Redis::class)) {
            throw new EventConfigException(
                'RedisStreamDriver 需要安装 hongxunpan/simple-redis ^0.1',
            );
        }
        if (!$app->make(ModuleConfig::class)->isEnabled(RedisModule::class)) {
            throw new EventConfigException('RedisStreamDriver 需要先启用 redis Module');
        }
        if (!$app->bound(Redis::class) || !$app->bound(RedisConfig::class)) {
            throw new EventConfigException('redis Module 尚未完成容器绑定');
        }

        $connections = $app->make(RedisConfig::class)->connections();
        if (!array_key_exists($options->connection, $connections)) {
            throw new EventConfigException(
                "Redis Event Driver 连接不存在：{$options->connection}",
            );
        }

        $requiredMethods = ['xAdd', 'xGroup', 'xReadGroup', 'xAutoClaim', 'xAck', 'xDel'];
        if (!extension_loaded('redis') || !class_exists(\Redis::class)) {
            throw new EventConfigException('RedisStreamDriver 需要 phpredis 扩展');
        }
        foreach ($requiredMethods as $method) {
            if (!method_exists(\Redis::class, $method)) {
                throw new EventConfigException("RedisStreamDriver 需要 phpredis::{$method}()");
            }
        }
    }

    /** @return class-string<Consumer> */
    public static function consumerClass(): string
    {
        return RedisStreamConsumer::class;
    }

    public function publish(EventMessage $message): void
    {
        try {
            $payload = $this->serializer->serialize($message);
        } catch (Throwable $throwable) {
            throw new EventPublishException(
                "Event 序列化失败：{$message->eventId}",
                previous: $throwable,
            );
        }

        try {
            $streamId = $this->redis
                ->getConnection($this->config->connection)
                ->xAdd(
                    $this->config->stream,
                    '*',
                    [self::MESSAGE_FIELD => $payload],
                );
        } catch (Throwable $throwable) {
            throw new EventPublishException(
                "Event 发布到 Redis Stream 失败：{$message->eventId}",
                previous: $throwable,
            );
        }

        if (!is_string($streamId) || preg_match('/^\d+-\d+$/D', $streamId) !== 1) {
            throw new EventPublishException(
                "Redis Stream 未返回有效消息 ID：{$message->eventId}",
            );
        }
    }
}
