<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Listener;

use HongXunPan\SimpleEvent\Event;
use HongXunPan\SimpleEvent\Exception\EventConfigException;
use HongXunPan\SimpleEvent\Exception\EventConsumeException;
use HongXunPan\SimpleEvent\Validation\EventValidator;
use HongXunPan\SimpleEvent\Validation\ListenerValidator;

final class ListenerRegistry
{
    /** @var array<class-string<Event>, list<class-string>> */
    private array $listeners = [];

    public function __construct(
        private readonly EventValidator $events,
        private readonly ListenerValidator $listenerValidator,
    ) {
    }

    /**
     * @param class-string $eventClass
     * @param class-string $listenerClass
     */
    public function addListener(string $eventClass, string $listenerClass): void
    {
        $this->events->validate($eventClass);
        $this->listenerValidator->validate($listenerClass, $eventClass);

        $listeners = $this->listeners[$eventClass] ?? [];
        if (in_array($listenerClass, $listeners, true)) {
            throw new EventConfigException(
                "事件监听器重复注册：{$eventClass} -> {$listenerClass}",
            );
        }

        $this->listeners[$eventClass][] = $listenerClass;
    }

    /** @return list<class-string> */
    public function listenersFor(Event $event): array
    {
        return $this->listeners[$event::class] ?? [];
    }

    /**
     * @param list<class-string> $listeners
     */
    public function assertQueuedListenersRegistered(Event $event, array $listeners): void
    {
        $eventClass = $event::class;
        $registered = array_values(array_filter(
            $this->listenersFor($event),
            static fn (string $listenerClass): bool =>
                is_a($listenerClass, ShouldQueue::class, true),
        ));

        foreach ($listeners as $listenerClass) {
            if (!in_array($listenerClass, $registered, true)) {
                throw new EventConsumeException(
                    "消息包含未注册的异步监听器：{$eventClass} -> {$listenerClass}",
                );
            }
        }
    }
}
