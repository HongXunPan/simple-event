<?php

declare(strict_types=1);

use HongXunPan\SimpleEvent\Event;
use HongXunPan\SimpleEvent\Listener\ShouldQueue;
use HongXunPan\SimpleEvent\Message\EventMessage;
use HongXunPan\SimpleEvent\Serialization\SymfonySerializer;
use HongXunPan\SimpleEvent\Validation\EventValidator;
use HongXunPan\SimpleEvent\Validation\ListenerValidator;

enum SerializationRegressionStatus: string
{
    case Approved = 'approved';
}

final readonly class SerializationRegressionOccurred implements Event
{
    public const int VERSION = 2;

    public function __construct(
        public int $id,
        public string $name,
        public ?string $note,
        public bool $enabled,
        public float $score,
        public SerializationRegressionStatus $status,
        public DateTimeImmutable $approvedAt,
    ) {
    }
}

final readonly class SerializationRegressionEmptyOccurred implements Event
{
}

final readonly class SerializationRegressionQueuedListener implements ShouldQueue
{
    public function handle(SerializationRegressionOccurred $event): void
    {
    }
}

final readonly class SerializationRegressionEmptyQueuedListener implements ShouldQueue
{
    public function handle(SerializationRegressionEmptyOccurred $event): void
    {
    }
}

final readonly class SerializationRegressionWrongQueuedListener implements ShouldQueue
{
    public function handle(SerializationRegressionEmptyOccurred $event): void
    {
    }
}

final readonly class SerializationRegressionInvalidArrayOccurred implements Event
{
    /** @param list<int> $ids */
    public function __construct(public array $ids)
    {
    }
}

final readonly class SerializationRegressionInvalidArrayListener
{
    public function handle(SerializationRegressionInvalidArrayOccurred $event): void
    {
    }
}

final readonly class SerializationRegressionInvalidMixedOccurred implements Event
{
    public function __construct(public mixed $value)
    {
    }
}

final readonly class SerializationRegressionInvalidObjectOccurred implements Event
{
    public function __construct(public object $value)
    {
    }
}

final class SerializationRegressionModel
{
}

final readonly class SerializationRegressionInvalidModelOccurred implements Event
{
    public function __construct(public SerializationRegressionModel $model)
    {
    }
}

final readonly class SerializationRegressionInvalidUnionOccurred implements Event
{
    public function __construct(public int|string $value)
    {
    }
}

final readonly class SerializationRegressionInvalidPrivateOccurred implements Event
{
    public function __construct(private string $value)
    {
    }
}

final class SerializationRegressionInvalidMutableOccurred implements Event
{
    public function __construct(public string $value)
    {
    }
}

readonly class SerializationRegressionInvalidNonFinalOccurred implements Event
{
    public function __construct(public string $value)
    {
    }
}

final readonly class SerializationRegressionInvalidVersionOccurred implements Event
{
    public const int VERSION = 0;
}

function makeSerializationRegressionSerializer(): SymfonySerializer
{
    return new SymfonySerializer(new EventValidator(), new ListenerValidator());
}

function makeSerializationRegressionMessage(): EventMessage
{
    return new EventMessage(
        eventId: 'event-serialization-regression',
        createdAt: new DateTimeImmutable('2026-07-11T12:35:00.654321+08:00'),
        event: new SerializationRegressionOccurred(
            id: 7,
            name: '示例事件',
            note: null,
            enabled: true,
            score: 9.5,
            status: SerializationRegressionStatus::Approved,
            approvedAt: new DateTimeImmutable('2026-07-11T12:34:56.123456+08:00'),
        ),
        listeners: [SerializationRegressionQueuedListener::class],
        traceId: 'trace-serialization-regression',
    );
}

/** @return array{SymfonySerializer, array<string, mixed>} */
function makeSerializationRegressionPayload(): array
{
    $serializer = makeSerializationRegressionSerializer();
    $payload = json_decode(
        $serializer->serialize(makeSerializationRegressionMessage()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return [$serializer, $payload];
}
