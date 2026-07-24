<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Message;

use DateTimeImmutable;
use HongXunPan\SimpleEvent\Event;
use HongXunPan\SimpleEvent\Listener\ShouldQueue;
use HongXunPan\SimpleEvent\Trace\TraceIdProvider;

final readonly class EventMessageFactory
{
    public function __construct(private TraceIdProvider $traceIds)
    {
    }

    /**
     * @param list<class-string<ShouldQueue>> $listeners
     */
    public function make(Event $event, array $listeners): EventMessage
    {
        return new EventMessage(
            eventId: bin2hex(random_bytes(16)),
            createdAt: new DateTimeImmutable(),
            event: $event,
            listeners: $listeners,
            traceId: $this->traceIds->traceId(),
        );
    }
}
