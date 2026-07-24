<?php

declare(strict_types=1);

use HongXunPan\SimpleEvent\Consumer\ReceivedMessage;
use HongXunPan\SimpleEvent\Driver\Redis\RedisStreamConfig;
use HongXunPan\SimpleEvent\Driver\Redis\RedisStreamConsumer;
use HongXunPan\SimpleEvent\Driver\Redis\RedisStreamDriver;
use HongXunPan\SimpleEvent\Exception\EventConfigException;
use HongXunPan\SimpleEvent\Exception\EventConsumeException;
use HongXunPan\SimpleEvent\Exception\EventPublishException;

require_once __DIR__ . '/Support/RedisRegressionSupport.php';

$tests['Redis Driver 所有必填 option 缺失时均失败'] = static function (): void {
    foreach ([
        'connection',
        'stream',
        'group',
        'failed_stream',
        'block_ms',
        'batch_size',
        'claim_idle_ms',
        'failed_max_length',
    ] as $missingKey) {
        $options = makeRedisRegressionDriverConfig()->options();
        unset($options[$missingKey]);

        assertEventThrows(
            EventConfigException::class,
            static fn () => RedisStreamConfig::fromDriverConfig(
                \HongXunPan\SimpleEvent\Config\DriverConfig::fromArray([
                    'class' => RedisStreamDriver::class,
                    'options' => $options,
                ]),
            ),
            '缺少 option',
            "Redis Driver 缺少配置时未失败：{$missingKey}",
        );
    }
};

$tests['Redis Driver 拒绝相同的主 Stream 与失败 Stream'] = static function (): void {
    assertEventThrows(
        EventConfigException::class,
        static fn () => RedisStreamConfig::fromDriverConfig(
            makeRedisRegressionDriverConfig([
                'failed_stream' => 'simple-event:regression',
            ]),
        ),
        '不能相同',
        'Redis Driver 接受了相同的主 Stream 与失败 Stream',
    );
};

$tests['Redis Driver 将序列化异常包装为发布异常'] = static function (): void {
    $driver = new RedisStreamDriver(
        makeRedisRegressionDriverConfig(),
        new RedisRegressionThrowingSerializer(),
        makeRedisRegressionManager(new RedisRegressionClient()),
    );

    try {
        $driver->publish(makeRedisRegressionMessage());
    } catch (EventPublishException $exception) {
        assertEventSame(
            '测试序列化失败',
            $exception->getPrevious()?->getMessage(),
            '原始序列化异常未保留',
        );

        return;
    }

    throw new SimpleEventTestFailure('序列化异常未包装为 EventPublishException');
};

$tests['Redis Driver 将底层连接异常包装为发布异常'] = static function (): void {
    $client = new RedisRegressionClient();
    $client->xAddFailure = new RuntimeException('模拟 Redis 写入失败');
    $driver = new RedisStreamDriver(
        makeRedisRegressionDriverConfig(),
        new RedisRegressionFixedSerializer(),
        makeRedisRegressionManager($client),
    );

    try {
        $driver->publish(makeRedisRegressionMessage());
    } catch (EventPublishException $exception) {
        assertEventSame(
            '模拟 Redis 写入失败',
            $exception->getPrevious()?->getMessage(),
            'Redis 底层异常未保留',
        );

        return;
    }

    throw new SimpleEventTestFailure('Redis 异常未包装为 EventPublishException');
};

$tests['Redis Driver 单次发布只写入一个 message 字段'] = static function (): void {
    $client = new RedisRegressionClient();
    $driver = new RedisStreamDriver(
        makeRedisRegressionDriverConfig(),
        new RedisRegressionFixedSerializer(),
        makeRedisRegressionManager($client),
    );

    $driver->publish(makeRedisRegressionMessage());

    $calls = $client->callsFor('xAdd');
    assertEventSame(1, count($calls), '单次发布未严格执行一次 XADD');
    assertEventSame(
        ['message' => '{"message_version":2}'],
        $calls[0]['arguments'][2],
        'Stream entry 未保持单 message 字段',
    );
};

$tests['Redis Driver 拒绝无效的 Stream 消息 ID'] = static function (): void {
    $client = new RedisRegressionClient();
    $client->xAddResult = false;
    $driver = new RedisStreamDriver(
        makeRedisRegressionDriverConfig(),
        new RedisRegressionFixedSerializer(),
        makeRedisRegressionManager($client),
    );

    assertEventThrows(
        EventPublishException::class,
        static fn () => $driver->publish(makeRedisRegressionMessage()),
        '未返回有效消息 ID',
        'Redis Driver 接受了无效 Stream 消息 ID',
    );
};

$tests['Redis Consumer 的 XACK 失败会阻止删除'] = static function (): void {
    $client = new RedisRegressionClient();
    $client->xAckFailure = new RuntimeException('模拟 XACK 失败');
    $consumer = new RedisStreamConsumer(
        makeRedisRegressionDriverConfig(),
        makeRedisRegressionManager($client),
        'consumer-regression',
    );

    assertEventThrows(
        EventConsumeException::class,
        static fn () => $consumer->acknowledge(new ReceivedMessage('1-0', 'payload')),
        'ACK 失败',
        'XACK 失败未转换为消费异常',
    );
    assertEventSame([], $client->callsFor('xDel'), 'XACK 失败后仍删除了消息');
};

$tests['Redis Consumer 的 XDEL 失败不推翻 ACK 终态'] = static function (): void {
    $client = new RedisRegressionClient();
    $client->xDelFailure = new RuntimeException('模拟 XDEL 失败');
    $consumer = new RedisStreamConsumer(
        makeRedisRegressionDriverConfig(),
        makeRedisRegressionManager($client),
        'consumer-regression',
    );

    $consumer->acknowledge(new ReceivedMessage('1-0', 'payload'));

    assertEventSame(1, count($client->callsFor('xAck')), '消息未执行 XACK');
    assertEventSame(1, count($client->callsFor('xDel')), '消息未尝试执行 XDEL');
};

$tests['Redis Consumer 优先回收 pending 并限制单批总量'] = static function (): void {
    $client = new RedisRegressionClient();
    $client->autoClaimResult = [
        '0-0',
        ['1-0' => ['message' => 'pending-payload']],
    ];
    $client->readGroupResult = [
        'simple-event:regression' => [
            '2-0' => ['message' => 'new-payload'],
        ],
    ];
    $consumer = new RedisStreamConsumer(
        makeRedisRegressionDriverConfig(['batch_size' => 2]),
        makeRedisRegressionManager($client),
        'consumer-regression',
    );

    $messages = iterator_to_array($consumer->receive());

    assertEventSame(['1-0', '2-0'], array_column($messages, 'id'), 'pending 与新消息顺序错误');
    $readCalls = $client->callsFor('xReadGroup');
    assertEventSame(1, $readCalls[0]['arguments'][3], '读取新消息时未扣减 pending 数量');
};

$tests['Redis Consumer 归档失败摘要后才确认原消息'] = static function (): void {
    $client = new RedisRegressionClient();
    $consumer = new RedisStreamConsumer(
        makeRedisRegressionDriverConfig(),
        makeRedisRegressionManager($client),
        'consumer-regression',
    );
    $message = new ReceivedMessage('1-0', 'source-payload');

    $consumer->fail($message, makeRedisRegressionFailure());

    $xAddCalls = $client->callsFor('xAdd');
    assertEventSame(1, count($xAddCalls), '失败摘要未写入 failed stream');
    $fields = $xAddCalls[0]['arguments'][2];
    assertEventSame('source-payload', $fields['message'], '失败归档未保留原消息');
    $failure = json_decode($fields['failure'], true, flags: JSON_THROW_ON_ERROR);
    assertEventSame('consumer-regression', $failure['consumer'], '失败摘要未保留 consumer');
    assertEventSame(1, count($client->callsFor('xAck')), '归档成功后未确认原消息');
    assertEventSame(1, count($client->callsFor('xDel')), '归档成功后未删除原消息');
};

$tests['Redis Consumer 写入 failed stream 失败时不确认原消息'] = static function (): void {
    $client = new RedisRegressionClient();
    $client->xAddFailure = new RuntimeException('模拟 failed stream 写入失败');
    $consumer = new RedisStreamConsumer(
        makeRedisRegressionDriverConfig(),
        makeRedisRegressionManager($client),
        'consumer-regression',
    );

    assertEventThrows(
        EventConsumeException::class,
        static fn () => $consumer->fail(
            new ReceivedMessage('1-0', 'source-payload'),
            makeRedisRegressionFailure(),
        ),
        'failed stream 失败',
        'failed stream 写入失败未转换为消费异常',
    );
    assertEventSame([], $client->callsFor('xAck'), '归档失败后仍确认了原消息');
    assertEventSame([], $client->callsFor('xDel'), '归档失败后仍删除了原消息');
};

$tests['Redis Consumer 将缺失 message 字段转换为空消息体'] = static function (): void {
    $client = new RedisRegressionClient();
    $client->readGroupResult = [
        'simple-event:regression' => [
            '1-0' => ['unexpected' => 'value'],
        ],
    ];
    $consumer = new RedisStreamConsumer(
        makeRedisRegressionDriverConfig(),
        makeRedisRegressionManager($client),
        'consumer-regression',
    );

    $messages = iterator_to_array($consumer->receive());

    assertEventSame('', $messages[0]->body, '缺失 message 字段未转换为空消息体');
};
