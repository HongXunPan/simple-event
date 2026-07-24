<?php

declare(strict_types=1);

use HongXunPan\Framework\Lifecycle\ApplicationLifecycle;
use HongXunPan\Framework\Lifecycle\ExceptionOccurredSnapshot;
use HongXunPan\Framework\Lifecycle\RequestHandledSnapshot;
use HongXunPan\SimpleEvent\EventServiceProvider;
use HongXunPan\SimpleEvent\Lifecycle\ExceptionOccurred;
use HongXunPan\SimpleEvent\Lifecycle\RequestHandled;

final class RequestHandledListener
{
    public static int $calls = 0;

    public function handle(RequestHandled $event): void
    {
        self::$calls++;
    }
}

final class ExceptionOccurredListener
{
    public static ?ExceptionOccurred $event = null;

    public function handle(ExceptionOccurred $event): void
    {
        self::$event = $event;
    }
}

$tests['EventApplicationLifecycle 转换完成与异常快照'] = static function (): void {
    RequestHandledListener::$calls = 0;
    ExceptionOccurredListener::$event = null;
    $app = makeEventApplication([
        'events' => [
            'listeners' => [
                RequestHandled::class => [RequestHandledListener::class],
                ExceptionOccurred::class => [ExceptionOccurredListener::class],
            ],
        ],
    ]);
    $provider = new EventServiceProvider();
    $provider->register($app);
    $provider->boot($app);

    $lifecycle = $app->make(ApplicationLifecycle::class);
    $lifecycle->requestHandled(new RequestHandledSnapshot());
    $lifecycle->exceptionOccurred(new ExceptionOccurredSnapshot(
        new RuntimeException(
            'secret=sample-secret mobile=13800138000',
            42,
        ),
    ));

    assertEventSame(1, RequestHandledListener::$calls, 'RequestHandled 未分发');
    assertEventSame(
        RuntimeException::class,
        ExceptionOccurredListener::$event?->exceptionClass,
        '异常类快照错误',
    );
    assertEventSame(42, ExceptionOccurredListener::$event?->code, '异常 code 快照错误');
    assertEventTrue(
        !str_contains(ExceptionOccurredListener::$event?->message ?? '', 'sample-secret'),
        '生命周期异常消息泄漏 secret',
    );
    assertEventTrue(
        !str_contains(ExceptionOccurredListener::$event?->message ?? '', '13800138000'),
        '生命周期异常消息泄漏手机号',
    );
};
