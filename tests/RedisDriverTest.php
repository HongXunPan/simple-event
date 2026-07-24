<?php

declare(strict_types=1);

use HongXunPan\DB\Redis\Redis;
use HongXunPan\Framework\Module\ModuleConfig;
use HongXunPan\SimpleEvent\Dispatch\Dispatcher;
use HongXunPan\SimpleEvent\Driver\Redis\RedisStreamDriver;
use HongXunPan\SimpleEvent\Event;
use HongXunPan\SimpleEvent\EventServiceProvider;
use HongXunPan\SimpleEvent\Exception\EventConfigException;
use HongXunPan\SimpleEvent\Listener\ShouldQueue;
use HongXunPan\SimpleEvent\Worker\EventWorker;
use HongXunPan\SimpleRedis\RedisServiceProvider;

final readonly class RedisSampleOccurred implements Event
{
    public function __construct(public string $name)
    {
    }
}

final class RedisSampleListener implements ShouldQueue
{
    public static int $calls = 0;

    public function handle(RedisSampleOccurred $event): void
    {
        self::$calls++;
    }
}

final class RedisFailingSampleListener implements ShouldQueue
{
    public function handle(RedisSampleOccurred $event): void
    {
        throw new RuntimeException('token=secret-value mobile=13800138000');
    }
}

/**
 * @return array{events: array<string, mixed>, redis: array<string, mixed>}
 */
function redisEventTestConfig(string $connection = 'default'): array
{
    $suffix = (string)(getmypid() ?: 0);

    return [
        'events' => [
            'listeners' => [
                RedisSampleOccurred::class => [RedisSampleListener::class],
            ],
            'driver' => [
                'class' => RedisStreamDriver::class,
                'options' => [
                    'connection' => $connection,
                    'stream' => "events-{$suffix}",
                    'group' => 'simple-event-tests',
                    'failed_stream' => "events-failed-{$suffix}",
                    'block_ms' => 10,
                    'batch_size' => 10,
                    'claim_idle_ms' => 1000,
                    'failed_max_length' => 100,
                ],
            ],
        ],
        'redis' => [
            'connections' => [
                'default' => [
                    'host' => 'gplus-redis',
                    'port' => 6379,
                    'database' => 15,
                    'prefix' => 'simple-event-tests:',
                ],
            ],
        ],
    ];
}

$tests['Redis Driver 要求 redis Module 已启用'] = static function (): void {
    $app = makeEventApplication(redisEventTestConfig());
    $app->instance(
        ModuleConfig::class,
        new ModuleConfig(__DIR__ . '/fixtures/redis-disabled'),
    );
    $eventProvider = new EventServiceProvider();
    $redisProvider = new RedisServiceProvider();
    $eventProvider->register($app);
    $redisProvider->register($app);

    assertEventThrows(
        EventConfigException::class,
        static fn () => $eventProvider->boot($app),
        '启用 redis Module',
        'Redis Driver 在 redis Module 未启用时仍完成启动',
    );
};

$tests['Redis Driver 拒绝不存在的命名连接'] = static function (): void {
    $app = makeEventApplication(redisEventTestConfig('missing'));
    $app->instance(
        ModuleConfig::class,
        new ModuleConfig(__DIR__ . '/fixtures/redis-enabled'),
    );
    $eventProvider = new EventServiceProvider();
    $redisProvider = new RedisServiceProvider();
    $eventProvider->register($app);
    $redisProvider->register($app);

    assertEventThrows(
        EventConfigException::class,
        static fn () => $eventProvider->boot($app),
        '连接不存在',
        'Redis Driver 接受了不存在的命名连接',
    );
};

$tests['Redis Streams 完成发布、白名单消费与确认'] = static function (): void {
    RedisSampleListener::$calls = 0;
    $configuration = redisEventTestConfig();
    $options = $configuration['events']['driver']['options'];
    $app = makeEventApplication($configuration);
    $app->instance(
        ModuleConfig::class,
        new ModuleConfig(__DIR__ . '/fixtures/redis-enabled'),
    );
    $eventProvider = new EventServiceProvider();
    $redisProvider = new RedisServiceProvider();
    $eventProvider->register($app);
    $redisProvider->register($app);
    $eventProvider->boot($app);
    $redisProvider->boot($app);

    $redis = $app->make(Redis::class);
    assertEventTrue(
        !$redis->hasConnection(),
        'Provider 启动阶段提前建立了 Redis 网络连接',
    );

    try {
        $app->make(Dispatcher::class)->dispatch(new RedisSampleOccurred('sample'));
        assertEventTrue($redis->hasConnection(), '首次发布没有建立 Redis 连接');

        $processed = $app->make(EventWorker::class)->runOnce();
        assertEventSame(1, $processed, 'Redis Worker 处理数量错误');
        assertEventSame(1, RedisSampleListener::$calls, 'Redis 异步监听器未执行');
    } finally {
        $connection = $redis->getConnection();
        $connection->del($options['stream']);
        $connection->del($options['failed_stream']);
        Redis::clearGlobal();
    }
};

$tests['Redis Streams 将监听器失败归档并清理主消息'] = static function (): void {
    $configuration = redisEventTestConfig();
    $configuration['events']['listeners'] = [
        RedisSampleOccurred::class => [RedisFailingSampleListener::class],
    ];
    $options = $configuration['events']['driver']['options'];
    $app = makeEventApplication($configuration);
    $app->instance(
        ModuleConfig::class,
        new ModuleConfig(__DIR__ . '/fixtures/redis-enabled'),
    );
    $eventProvider = new EventServiceProvider();
    $redisProvider = new RedisServiceProvider();
    $eventProvider->register($app);
    $redisProvider->register($app);
    $eventProvider->boot($app);
    $redisProvider->boot($app);
    $redis = $app->make(Redis::class);

    try {
        $app->make(Dispatcher::class)->dispatch(new RedisSampleOccurred('failed'));
        assertEventSame(1, $app->make(EventWorker::class)->runOnce(), '失败消息处理数量错误');

        $connection = $redis->getConnection();
        assertEventSame(0, $connection->xLen($options['stream']), '失败消息仍残留主 Stream');
        $failedEntries = $connection->xRange($options['failed_stream'], '-', '+');
        assertEventSame(1, count($failedEntries), '失败消息未归档到 failed stream');
        $fields = array_values($failedEntries)[0];
        $failure = json_decode($fields['failure'], true, flags: JSON_THROW_ON_ERROR);
        assertEventSame(
            'token=[REDACTED] mobile=1**********',
            $failure['listeners'][0]['error_message'],
            '失败摘要未清洗敏感信息',
        );
        assertEventTrue(!empty($failure['consumer']), '失败摘要缺少 Redis consumer');
    } finally {
        $connection = $redis->getConnection();
        $connection->del($options['stream']);
        $connection->del($options['failed_stream']);
        Redis::clearGlobal();
    }
};

$tests['Redis Streams 将非法 JSON 消息归档到 failed stream'] = static function (): void {
    $configuration = redisEventTestConfig();
    $options = $configuration['events']['driver']['options'];
    $app = makeEventApplication($configuration);
    $app->instance(
        ModuleConfig::class,
        new ModuleConfig(__DIR__ . '/fixtures/redis-enabled'),
    );
    $eventProvider = new EventServiceProvider();
    $redisProvider = new RedisServiceProvider();
    $eventProvider->register($app);
    $redisProvider->register($app);
    $eventProvider->boot($app);
    $redisProvider->boot($app);
    $redis = $app->make(Redis::class);
    $connection = $redis->getConnection();

    try {
        $connection->xAdd($options['stream'], '*', ['message' => '{invalid-json']);
        assertEventSame(1, $app->make(EventWorker::class)->runOnce(), '非法 JSON 消息未处理');
        assertEventSame(0, $connection->xLen($options['stream']), '非法 JSON 仍残留主 Stream');
        assertEventSame(
            1,
            $connection->xLen($options['failed_stream']),
            '非法 JSON 未归档到 failed stream',
        );
    } finally {
        $connection->del($options['stream']);
        $connection->del($options['failed_stream']);
        Redis::clearGlobal();
    }
};
