<?php

declare(strict_types=1);

use HongXunPan\Framework\Core\Application;
use HongXunPan\SimpleEvent\Config\DriverConfig;
use HongXunPan\SimpleEvent\Consumer\Consumer;
use HongXunPan\SimpleEvent\Consumer\ReceivedMessage;
use HongXunPan\SimpleEvent\Driver\Driver;
use HongXunPan\SimpleEvent\Event;
use HongXunPan\SimpleEvent\EventServiceProvider;
use HongXunPan\SimpleEvent\Exception\EventConfigException;
use HongXunPan\SimpleEvent\Execution\Failure;
use HongXunPan\SimpleEvent\Message\EventMessage;

final readonly class ProviderValidationOccurred implements Event
{
    public function __construct(public string $name)
    {
    }
}

final readonly class ProviderValidationOtherOccurred implements Event
{
}

final class ProviderValidationMissingHandleListener
{
}

final class ProviderValidationWrongEventListener
{
    public function handle(ProviderValidationOtherOccurred $event): void
    {
    }
}

final class ProviderValidationInvalidReturnListener
{
    public function handle(ProviderValidationOccurred $event): int
    {
        return 1;
    }
}

final class ProviderValidationValidListener
{
    public function handle(ProviderValidationOccurred $event): void
    {
    }
}

final class ProviderValidationNotDriver
{
}

final readonly class ProviderValidationConsumer implements Consumer
{
    public function receive(): iterable
    {
        return [];
    }

    public function acknowledge(ReceivedMessage $message): void
    {
    }

    public function fail(ReceivedMessage $message, Failure $failure): void
    {
    }
}

final readonly class ProviderValidationInvalidConsumerDriver implements Driver
{
    public static function validateConfig(DriverConfig $config, Application $app): void
    {
    }

    public static function consumerClass(): string
    {
        return ProviderValidationNotDriver::class;
    }

    public function publish(EventMessage $message): void
    {
    }
}

final readonly class ProviderValidationRejectingDriver implements Driver
{
    public static function validateConfig(DriverConfig $config, Application $app): void
    {
        throw new EventConfigException('测试 Driver 配置无效');
    }

    public static function consumerClass(): string
    {
        return ProviderValidationConsumer::class;
    }

    public function publish(EventMessage $message): void
    {
    }
}

/**
 * @param array<string, mixed> $events
 */
function bootProviderValidationApplication(array $events): Application
{
    $app = makeEventApplication(['events' => $events]);
    $provider = new EventServiceProvider();
    $provider->register($app);
    $provider->boot($app);

    return $app;
}

$tests['未实现 Driver 的类在 Provider 启动时失败'] = static function (): void {
    assertEventThrows(
        EventConfigException::class,
        static fn () => bootProviderValidationApplication([
            'listeners' => [],
            'driver' => [
                'class' => ProviderValidationNotDriver::class,
                'options' => [],
            ],
        ]),
        '必须实现 Driver',
        '非法 Driver 完成了 Provider 启动',
    );
};

$tests['Driver 声明非法 Consumer 时启动失败'] = static function (): void {
    assertEventThrows(
        EventConfigException::class,
        static fn () => bootProviderValidationApplication([
            'listeners' => [],
            'driver' => [
                'class' => ProviderValidationInvalidConsumerDriver::class,
                'options' => [],
            ],
        ]),
        '必须返回 Consumer 类',
        '非法 Consumer 完成了 Provider 启动',
    );
};

$tests['Driver 自身配置校验异常保持原始语义'] = static function (): void {
    assertEventThrows(
        EventConfigException::class,
        static fn () => bootProviderValidationApplication([
            'listeners' => [],
            'driver' => [
                'class' => ProviderValidationRejectingDriver::class,
                'options' => [],
            ],
        ]),
        '测试 Driver 配置无效',
        'Driver 配置校验未在启动期执行',
    );
};

$tests['显式空 Driver 类名在 Provider 启动时失败'] = static function (): void {
    assertEventThrows(
        EventConfigException::class,
        static fn () => bootProviderValidationApplication([
            'listeners' => [],
            'driver' => [
                'class' => '',
                'options' => [],
            ],
        ]),
        'class 必须是非空类名',
        '显式空 Driver 类名完成了 Provider 启动',
    );
};

$tests['非法监听器在 Provider 启动时失败'] = static function (): void {
    foreach ([
        ProviderValidationMissingHandleListener::class,
        ProviderValidationWrongEventListener::class,
        ProviderValidationInvalidReturnListener::class,
    ] as $listenerClass) {
        assertEventThrows(
            EventConfigException::class,
            static fn () => bootProviderValidationApplication([
                'listeners' => [
                    ProviderValidationOccurred::class => [$listenerClass],
                ],
            ]),
            '事件监听器',
            "非法监听器完成了 Provider 启动：{$listenerClass}",
        );
    }
};

$tests['非法事件类在 Provider 启动时失败'] = static function (): void {
    assertEventThrows(
        EventConfigException::class,
        static fn () => bootProviderValidationApplication([
            'listeners' => [
                stdClass::class => [ProviderValidationValidListener::class],
            ],
        ]),
        '事件类必须是可实例化的 Event',
        '非法事件类完成了 Provider 启动',
    );
};

$tests['重复监听器在 Provider 启动时失败'] = static function (): void {
    assertEventThrows(
        EventConfigException::class,
        static fn () => bootProviderValidationApplication([
            'listeners' => [
                ProviderValidationOccurred::class => [
                    ProviderValidationValidListener::class,
                    ProviderValidationValidListener::class,
                ],
            ],
        ]),
        '重复注册',
        '重复监听器完成了 Provider 启动',
    );
};

$tests['非法 listeners 配置结构在 Provider 启动时失败'] = static function (): void {
    assertEventThrows(
        EventConfigException::class,
        static fn () => bootProviderValidationApplication([
            'listeners' => 'invalid-listeners',
        ]),
        'listeners 必须是数组',
        '非法 listeners 配置完成了 Provider 启动',
    );
};
