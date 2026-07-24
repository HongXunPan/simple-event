<?php

declare(strict_types=1);

use HongXunPan\SimpleEvent\EventServiceProvider;
use HongXunPan\SimpleEvent\Exception\EventConfigException;
use HongXunPan\SimpleEvent\Message\EventMessage;
use HongXunPan\SimpleEvent\Serialization\Serializer;
use HongXunPan\SimpleEvent\Validation\EventValidator;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use UnexpectedValueException;

require_once __DIR__ . '/Support/SerializationRegressionSupport.php';

$tests['Serializer 在容器内保持单例'] = static function (): void {
    $app = makeEventApplication();
    $provider = new EventServiceProvider();
    $provider->register($app);
    $provider->boot($app);

    assertEventSame(
        $app->make(Serializer::class),
        $app->make(Serializer::class),
        'Serializer 未保持容器单例',
    );
};

$tests['Serializer 完整往返标量枚举与微秒时间'] = static function (): void {
    $serializer = makeSerializationRegressionSerializer();
    $json = $serializer->serialize(makeSerializationRegressionMessage());
    $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $restored = $serializer->deserialize($json);

    assertEventSame(2, $payload['message_version'], '消息版本未写入 JSON');
    assertEventSame(
        SerializationRegressionOccurred::class,
        $payload['event_class'],
        '事件类未写入 JSON',
    );
    assertEventSame(2, $payload['event_version'], '事件版本未写入 JSON');
    assertEventSame('approved', $payload['payload']['status'], '枚举未转换为 backing value');
    assertEventSame(
        '2026-07-11T12:35:00.654321+08:00',
        $restored->createdAt->format('Y-m-d\TH:i:s.uP'),
        '消息时间往返错误',
    );
    assertEventSame(7, $restored->event->id, '整数快照字段往返错误');
    assertEventSame(null, $restored->event->note, '可空快照字段往返错误');
    assertEventSame(
        SerializationRegressionStatus::Approved,
        $restored->event->status,
        '枚举快照字段往返错误',
    );
    assertEventSame(
        '2026-07-11T12:34:56.123456+08:00',
        $restored->event->approvedAt->format('Y-m-d\TH:i:s.uP'),
        '事件时间字段往返错误',
    );
};

$tests['未声明 VERSION 的 Event 默认使用版本 1'] = static function (): void {
    assertEventSame(
        1,
        (new EventValidator())->versionOf(SerializationRegressionEmptyOccurred::class),
        'Event 默认版本不是 1',
    );
};

$tests['无属性 Event 使用空 JSON 对象并可往返'] = static function (): void {
    $serializer = makeSerializationRegressionSerializer();
    $json = $serializer->serialize(new EventMessage(
        eventId: 'event-empty-payload',
        createdAt: new DateTimeImmutable('2026-07-11T12:35:00+08:00'),
        event: new SerializationRegressionEmptyOccurred(),
        listeners: [SerializationRegressionEmptyQueuedListener::class],
    ));
    $restored = $serializer->deserialize($json);

    assertEventTrue(str_contains($json, '"payload":{}'), '空 Event payload 不是 JSON 对象');
    assertEventSame(
        SerializationRegressionEmptyOccurred::class,
        $restored->event::class,
        '无属性 Event 未正确恢复',
    );
};

$tests['非法 Event 快照在 Provider 启动期失败'] = static function (): void {
    $app = makeEventApplication([
        'events' => [
            'listeners' => [
                SerializationRegressionInvalidArrayOccurred::class => [
                    SerializationRegressionInvalidArrayListener::class,
                ],
            ],
        ],
    ]);
    $provider = new EventServiceProvider();
    $provider->register($app);

    assertEventThrows(
        EventConfigException::class,
        static fn () => $provider->boot($app),
        '属性类型不在 MVP 白名单',
        '非法 Event 快照完成了 Provider 启动',
    );
};

$tests['EventValidator 拒绝全部非白名单结构'] = static function (): void {
    $validator = new EventValidator();
    foreach ([
        SerializationRegressionInvalidArrayOccurred::class,
        SerializationRegressionInvalidMixedOccurred::class,
        SerializationRegressionInvalidObjectOccurred::class,
        SerializationRegressionInvalidModelOccurred::class,
        SerializationRegressionInvalidUnionOccurred::class,
        SerializationRegressionInvalidPrivateOccurred::class,
        SerializationRegressionInvalidMutableOccurred::class,
        SerializationRegressionInvalidNonFinalOccurred::class,
        SerializationRegressionInvalidVersionOccurred::class,
    ] as $eventClass) {
        assertEventThrows(
            EventConfigException::class,
            static fn () => $validator->validate($eventClass),
            '',
            "非法 Event 未被拒绝：{$eventClass}",
        );
    }
};

$tests['反序列化拒绝未知消息版本与事件版本'] = static function (): void {
    [$serializer, $payload] = makeSerializationRegressionPayload();
    $payload['message_version'] = 3;
    assertEventThrows(
        UnexpectedValueException::class,
        static fn () => $serializer->deserialize(json_encode($payload, JSON_THROW_ON_ERROR)),
        '不支持的 EventMessage 版本',
        '未知消息版本未被拒绝',
    );

    $payload['message_version'] = EventMessage::VERSION;
    $payload['event_version'] = 3;
    assertEventThrows(
        UnexpectedValueException::class,
        static fn () => $serializer->deserialize(json_encode($payload, JSON_THROW_ON_ERROR)),
        '不支持的 Event 版本',
        '未知事件版本未被拒绝',
    );
};

$tests['反序列化拒绝非法 JSON'] = static function (): void {
    assertEventThrows(
        NotEncodableValueException::class,
        static fn () => makeSerializationRegressionSerializer()->deserialize('{invalid-json'),
        '',
        '非法 JSON 未被拒绝',
    );
};

$tests['序列化拒绝非法 EventMessage 元数据'] = static function (): void {
    $serializer = makeSerializationRegressionSerializer();
    $event = makeSerializationRegressionMessage()->event;

    foreach ([
        new EventMessage(
            '',
            new DateTimeImmutable(),
            $event,
            [SerializationRegressionQueuedListener::class],
        ),
        new EventMessage(
            'event-empty-trace',
            new DateTimeImmutable(),
            $event,
            [SerializationRegressionQueuedListener::class],
            '',
        ),
        new EventMessage(
            'event-version',
            new DateTimeImmutable(),
            $event,
            [SerializationRegressionQueuedListener::class],
            null,
            3,
        ),
    ] as $message) {
        assertEventThrows(
            UnexpectedValueException::class,
            static fn () => $serializer->serialize($message),
            'Event',
            '非法 EventMessage 元数据未被拒绝',
        );
    }
};

$tests['反序列化拒绝宽松时间格式'] = static function (): void {
    [$serializer, $payload] = makeSerializationRegressionPayload();
    $payload['created_at'] = '2026-07-11 12:35:00';

    assertEventThrows(
        UnexpectedValueException::class,
        static fn () => $serializer->deserialize(json_encode($payload, JSON_THROW_ON_ERROR)),
        '不是合法的固定格式时间',
        '宽松时间格式未被拒绝',
    );
};

$tests['反序列化拒绝 EventMessage 顶层字段漂移'] = static function (): void {
    [$serializer, $payload] = makeSerializationRegressionPayload();
    $payload['unexpected'] = true;
    assertEventThrows(
        UnexpectedValueException::class,
        static fn () => $serializer->deserialize(json_encode($payload, JSON_THROW_ON_ERROR)),
        '字段必须精确匹配',
        'EventMessage 多余字段未被拒绝',
    );

    unset($payload['unexpected'], $payload['trace_id']);
    assertEventThrows(
        UnexpectedValueException::class,
        static fn () => $serializer->deserialize(json_encode($payload, JSON_THROW_ON_ERROR)),
        '字段必须精确匹配',
        'EventMessage 缺失字段未被拒绝',
    );
};

$tests['反序列化拒绝旧版 EventMessage 结构'] = static function (): void {
    [$serializer, $payload] = makeSerializationRegressionPayload();
    $payload['message_version'] = 1;
    $payload['occurred_at'] = $payload['created_at'];
    unset($payload['created_at']);

    assertEventThrows(
        UnexpectedValueException::class,
        static fn () => $serializer->deserialize(json_encode($payload, JSON_THROW_ON_ERROR)),
        '字段必须精确匹配',
        '旧版消息结构未被拒绝',
    );
};

$tests['反序列化拒绝缺失的 Event payload 字段'] = static function (): void {
    [$serializer, $payload] = makeSerializationRegressionPayload();
    unset($payload['payload']['name']);

    assertEventThrows(
        UnexpectedValueException::class,
        static fn () => $serializer->deserialize(json_encode($payload, JSON_THROW_ON_ERROR)),
        '字段必须精确匹配',
        'Event payload 缺失字段未被拒绝',
    );
};

$tests['反序列化拒绝重复 listener'] = static function (): void {
    [$serializer, $payload] = makeSerializationRegressionPayload();
    $payload['listeners'][] = SerializationRegressionQueuedListener::class;

    assertEventThrows(
        UnexpectedValueException::class,
        static fn () => $serializer->deserialize(json_encode($payload, JSON_THROW_ON_ERROR)),
        '不得重复',
        '重复 listener 未被拒绝',
    );
};

$tests['反序列化拒绝与 Event 不匹配的 listener'] = static function (): void {
    [$serializer, $payload] = makeSerializationRegressionPayload();
    $payload['listeners'] = [SerializationRegressionWrongQueuedListener::class];

    assertEventThrows(
        UnexpectedValueException::class,
        static fn () => $serializer->deserialize(json_encode($payload, JSON_THROW_ON_ERROR)),
        '契约不匹配',
        '与 Event 不匹配的 listener 未被拒绝',
    );
};
