<?php

declare(strict_types=1);

use HongXunPan\Framework\Core\Application;
use HongXunPan\SimpleEvent\Config\DriverConfig;
use HongXunPan\SimpleEvent\Config\EventConfig;
use HongXunPan\SimpleEvent\Consumer\Consumer;
use HongXunPan\SimpleEvent\Driver\Driver;
use HongXunPan\SimpleEvent\Exception\EventConfigException;
use HongXunPan\SimpleEvent\Message\EventMessage;

final readonly class ConfigTestDriver implements Driver
{
    public static function validateConfig(DriverConfig $config, Application $app): void
    {
    }

    public static function consumerClass(): string
    {
        return ConfigTestConsumer::class;
    }

    public function publish(EventMessage $message): void
    {
    }
}

final readonly class ConfigTestConsumer implements Consumer
{
    public function receive(): iterable
    {
        return [];
    }

    public function acknowledge(\HongXunPan\SimpleEvent\Consumer\ReceivedMessage $message): void
    {
    }

    public function fail(
        \HongXunPan\SimpleEvent\Consumer\ReceivedMessage $message,
        \HongXunPan\SimpleEvent\Execution\Failure $failure,
    ): void {
    }
}

$tests['标准配置只接受 listeners 与可空 driver'] = static function (): void {
    $config = EventConfig::fromArray([
        'listeners' => [],
        'driver' => [
            'class' => ConfigTestDriver::class,
            'options' => ['name' => 'sample'],
        ],
    ]);

    assertEventSame([], $config->listeners(), '空监听器配置错误');
    assertEventSame(ConfigTestDriver::class, $config->driver?->class, 'Driver 类错误');
    assertEventSame(['name' => 'sample'], $config->driver?->options(), 'Driver options 错误');
};

$tests['Event 配置拒绝未知顶层键和旧式扁平 Driver 键'] = static function (): void {
    assertEventThrows(
        EventConfigException::class,
        static fn () => EventConfig::fromArray([
            'listeners' => [],
            'connection' => 'default',
        ]),
        '未知顶层配置项',
        'Event 配置接受了未知顶层键',
    );

    assertEventThrows(
        EventConfigException::class,
        static fn () => DriverConfig::fromArray([
            'class' => ConfigTestDriver::class,
            'connection' => 'default',
        ]),
        '未知配置项',
        'Driver 配置接受了 options 外的实现参数',
    );
};

$tests['默认配置不启用 Driver'] = static function (): void {
    $configuration = require dirname(__DIR__) . '/resources/config/events.php';
    $config = EventConfig::fromArray($configuration);

    assertEventSame([], $config->listeners(), '包内默认 listeners 不为空');
    assertEventSame(null, $config->driver, '包内默认配置错误启用了 Driver');
};
