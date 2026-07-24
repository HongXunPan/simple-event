<?php

declare(strict_types=1);

use DateTimeImmutable;
use HongXunPan\SimpleEvent\Event;
use HongXunPan\SimpleEvent\Listener\ShouldQueue;
use HongXunPan\SimpleEvent\Message\EventMessage;
use HongXunPan\SimpleEvent\Serialization\SymfonySerializer;
use HongXunPan\SimpleEvent\Validation\EventValidator;
use HongXunPan\SimpleEvent\Validation\ListenerValidator;
use UnexpectedValueException;

final readonly class SerializedSampleOccurred implements Event
{
    public const int VERSION = 1;

    public function __construct(
        public string $name,
        public int $sequence,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}

final class SerializedSampleListener implements ShouldQueue
{
    public function handle(SerializedSampleOccurred $event): void
    {
    }
}

$tests['Symfony Serializer 严格往返 EventMessage'] = static function (): void {
    $serializer = new SymfonySerializer(new EventValidator(), new ListenerValidator());
    $message = new EventMessage(
        eventId: 'sample-event-id',
        createdAt: new DateTimeImmutable('2026-07-24T10:00:00+08:00'),
        event: new SerializedSampleOccurred(
            'sample',
            7,
            new DateTimeImmutable('2026-07-24T09:59:00+08:00'),
        ),
        listeners: [SerializedSampleListener::class],
        traceId: 'sample-trace-id',
    );

    $restored = $serializer->deserialize($serializer->serialize($message));

    assertEventSame($message->eventId, $restored->eventId, 'eventId 往返错误');
    assertEventSame($message->traceId, $restored->traceId, 'traceId 往返错误');
    assertEventSame($message->listeners, $restored->listeners, 'listener 往返错误');
    assertEventSame('sample', $restored->event->name, 'Event payload 往返错误');
};

$tests['反序列化拒绝 Event payload 字段漂移'] = static function (): void {
    $serializer = new SymfonySerializer(new EventValidator(), new ListenerValidator());
    $message = new EventMessage(
        eventId: 'sample-event-id',
        createdAt: new DateTimeImmutable(),
        event: new SerializedSampleOccurred('sample', 1, new DateTimeImmutable()),
        listeners: [SerializedSampleListener::class],
    );
    $payload = json_decode($serializer->serialize($message), true, flags: JSON_THROW_ON_ERROR);
    $payload['payload']['unexpected'] = 'value';

    assertEventThrows(
        UnexpectedValueException::class,
        static fn () => $serializer->deserialize(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        )),
        '字段必须精确匹配',
        'Serializer 接受了漂移字段',
    );
};
