<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Dispatch;

use HongXunPan\SimpleEvent\Driver\Driver;
use HongXunPan\SimpleEvent\Event;
use HongXunPan\SimpleEvent\Exception\EventConfigException;
use HongXunPan\SimpleEvent\Listener\ListenerInvoker;
use HongXunPan\SimpleEvent\Listener\ListenerRegistry;
use HongXunPan\SimpleEvent\Listener\ShouldQueue;
use HongXunPan\SimpleEvent\Message\EventMessageFactory;

final readonly class Dispatcher
{
    public function __construct(
        private ListenerRegistry $listeners,
        private ListenerInvoker $invoker,
        private EventMessageFactory $messages,
        private ?Driver $driver,
    ) {
    }

    /**
     * @param class-string $eventClass
     * @param class-string $listenerClass
     */
    public function addListener(string $eventClass, string $listenerClass): void
    {
        if (is_a($listenerClass, ShouldQueue::class, true) && $this->driver === null) {
            throw new EventConfigException('注册 ShouldQueue 监听器前必须配置 Event Driver');
        }

        $this->listeners->addListener($eventClass, $listenerClass);
    }

    public function dispatch(Event $event): void
    {
        /** @var list<class-string<ShouldQueue>> $queuedListeners */
        $queuedListeners = [];

        foreach ($this->listeners->listenersFor($event) as $listenerClass) {
            if (is_a($listenerClass, ShouldQueue::class, true)) {
                $queuedListeners[] = $listenerClass;
                continue;
            }

            $this->invoker->invoke($listenerClass, $event);
        }

        if ($queuedListeners === []) {
            return;
        }
        if ($this->driver === null) {
            throw new EventConfigException('异步监听器缺少 Event Driver');
        }

        $this->driver->publish($this->messages->make($event, $queuedListeners));
    }
}
