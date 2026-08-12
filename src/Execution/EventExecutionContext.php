<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Execution;

use HongXunPan\SimpleEvent\Message\EventMessage;

final class EventExecutionContext
{
    private bool $active = false;
    private ?string $messageId = null;
    private ?string $eventId = null;
    private ?string $traceId = null;
    /** @var class-string|null */
    private ?string $eventClass = null;
    /** @var class-string|null */
    private ?string $listenerClass = null;

    public function beginMessage(string $messageId): void
    {
        $this->clear();
        $this->active = true;
        $this->messageId = $messageId;
    }

    public function attachEventMessage(EventMessage $message): void
    {
        $this->eventId = $message->eventId;
        $this->traceId = $message->traceId;
        $this->eventClass = $message->event::class;
    }

    /** @param class-string $listenerClass */
    public function enterListener(string $listenerClass): void
    {
        $this->listenerClass = $listenerClass;
    }

    public function leaveListener(): void
    {
        $this->listenerClass = null;
    }

    public function clear(): void
    {
        $this->active = false;
        $this->messageId = null;
        $this->eventId = null;
        $this->traceId = null;
        $this->eventClass = null;
        $this->listenerClass = null;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    /** @return array<string, string> */
    public function snapshot(): array
    {
        $context = [];
        foreach ([
            'message_id' => $this->messageId,
            'event_id' => $this->eventId,
            'trace_id' => $this->traceId,
            'event_class' => $this->eventClass,
            'listener_class' => $this->listenerClass,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $context[$key] = $value;
            }
        }

        return $context;
    }
}
