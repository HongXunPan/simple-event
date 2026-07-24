<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Config;

use HongXunPan\SimpleEvent\Driver\Driver;
use HongXunPan\SimpleEvent\Exception\EventConfigException;

final readonly class DriverConfig
{
    /**
     * @param class-string<Driver> $class
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $class,
        private array $options,
    ) {
        if (!class_exists($this->class) || !is_a($this->class, Driver::class, true)) {
            throw new EventConfigException("Event Driver 必须实现 Driver：{$this->class}");
        }
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public static function fromArray(array $configuration): self
    {
        $unknownKeys = array_diff(array_keys($configuration), ['class', 'options']);
        if ($unknownKeys !== []) {
            throw new EventConfigException(
                'Event Driver 包含未知配置项：' . implode('、', $unknownKeys),
            );
        }

        $class = $configuration['class'] ?? null;
        if (!is_string($class) || $class === '') {
            throw new EventConfigException('Event Driver class 必须是非空类名');
        }

        $options = $configuration['options'] ?? [];
        if (!is_array($options)) {
            throw new EventConfigException('Event Driver options 必须是数组');
        }

        return new self($class, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }
}
