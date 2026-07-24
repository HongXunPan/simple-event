<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Config;

use HongXunPan\SimpleEvent\Event;
use HongXunPan\SimpleEvent\Exception\EventConfigException;
use HongXunPan\SimpleEvent\Listener\ShouldQueue;

final readonly class EventConfig
{
    /**
     * @param array<class-string<Event>, list<class-string>> $listeners
     */
    public function __construct(
        private array $listeners,
        public ?DriverConfig $driver,
    ) {
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public static function fromArray(array $configuration): self
    {
        $unknownKeys = array_diff(array_keys($configuration), ['listeners', 'driver']);
        if ($unknownKeys !== []) {
            throw new EventConfigException(
                'Event 配置包含未知顶层配置项：' . implode('、', $unknownKeys),
            );
        }

        $listeners = $configuration['listeners'] ?? [];
        if (!is_array($listeners)) {
            throw new EventConfigException('Event listeners 必须是数组');
        }

        $validated = [];
        foreach ($listeners as $eventClass => $eventListeners) {
            if (!is_string($eventClass) || $eventClass === '' || !is_array($eventListeners)) {
                throw new EventConfigException('Event listeners 必须使用事件类名映射监听器列表');
            }
            if (!array_is_list($eventListeners)) {
                throw new EventConfigException("{$eventClass} 的监听器必须是类名列表");
            }
            foreach ($eventListeners as $listenerClass) {
                if (!is_string($listenerClass) || $listenerClass === '') {
                    throw new EventConfigException("{$eventClass} 的监听器必须是非空类名");
                }
            }
            $validated[$eventClass] = $eventListeners;
        }

        $driver = null;
        if (array_key_exists('driver', $configuration)) {
            if (!is_array($configuration['driver'])) {
                throw new EventConfigException('Event driver 必须是数组');
            }
            $driver = DriverConfig::fromArray($configuration['driver']);
        }

        return new self($validated, $driver);
    }

    /**
     * @return array<class-string<Event>, list<class-string>>
     */
    public function listeners(): array
    {
        return $this->listeners;
    }

    public function hasQueuedListeners(): bool
    {
        foreach ($this->listeners as $listeners) {
            foreach ($listeners as $listenerClass) {
                if (is_a($listenerClass, ShouldQueue::class, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
