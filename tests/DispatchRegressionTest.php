<?php

declare(strict_types=1);

use HongXunPan\SimpleEvent\Consumer\Consumer;
use HongXunPan\SimpleEvent\Dispatch\Dispatcher;
use HongXunPan\SimpleEvent\Driver\Driver;
use HongXunPan\SimpleEvent\Exception\EventConfigException;

require_once __DIR__ . '/Support/DispatchRegressionSupport.php';

$tests['无 events 配置时 Dispatcher 安全空运行'] = static function (): void {
    [$app, $log] = bootDispatchRegressionApplication(withEventsConfig: false);

    $app->make(Dispatcher::class)->dispatch(new DispatchRegressionOccurred('empty'));

    assertEventSame([], $log->entries, '无配置时仍执行了监听器');
};

$tests['Dispatcher 与 Driver Consumer 均保持容器单例'] = static function (): void {
    [$app] = bootDispatchRegressionApplication(withDriver: true);

    assertEventSame(
        $app->make(Dispatcher::class),
        $app->make(Dispatcher::class),
        'Dispatcher 未保持单例',
    );
    assertEventSame(
        $app->make(Consumer::class),
        $app->make(Consumer::class),
        'Consumer 未保持单例',
    );
};

$tests['Dispatcher 支持手动注册同步监听器'] = static function (): void {
    [$app, $log] = bootDispatchRegressionApplication();
    $dispatcher = $app->make(Dispatcher::class);
    $dispatcher->addListener(
        DispatchRegressionOccurred::class,
        DispatchRegressionFirstListener::class,
    );

    $dispatcher->dispatch(new DispatchRegressionOccurred('manual'));

    assertEventSame(['first:manual'], $log->entries, '手动同步监听器未被执行');
};

$tests['手动注册异步监听器复用已配置 Driver'] = static function (): void {
    [$app] = bootDispatchRegressionApplication(withDriver: true);
    $dispatcher = $app->make(Dispatcher::class);
    $dispatcher->addListener(
        DispatchRegressionOccurred::class,
        DispatchRegressionFirstQueuedListener::class,
    );

    $dispatcher->dispatch(new DispatchRegressionOccurred('manual-queued'));

    $driver = $app->make(Driver::class);
    assertEventTrue($driver instanceof DispatchRegressionDriver, '容器 Driver 类型错误');
    assertEventSame(1, count($driver->messages), '手动异步监听器未发布唯一消息');
};

$tests['手动注册异步监听器缺少 Driver 时立即失败'] = static function (): void {
    [$app] = bootDispatchRegressionApplication();

    assertEventThrows(
        EventConfigException::class,
        static fn () => $app->make(Dispatcher::class)->addListener(
            DispatchRegressionOccurred::class,
            DispatchRegressionFirstQueuedListener::class,
        ),
        '必须配置 Event Driver',
        '手动异步注册绕过了 Driver 门禁',
    );
};

$tests['同步异常原样传播并停止后续监听器'] = static function (): void {
    [$app, $log] = bootDispatchRegressionApplication([
        DispatchRegressionOccurred::class => [
            DispatchRegressionFirstListener::class,
            DispatchRegressionThrowingListener::class,
            DispatchRegressionSecondListener::class,
        ],
    ]);

    assertEventThrows(
        RuntimeException::class,
        static fn () => $app->make(Dispatcher::class)->dispatch(
            new DispatchRegressionOccurred('failed'),
        ),
        '同步监听器失败',
        '同步监听器异常未原样传播',
    );
    assertEventSame(
        ['first:failed', 'throwing:failed'],
        $log->entries,
        '同步异常后仍执行了后续监听器',
    );
};

$tests['best-effort 上报器异常不污染同步调用链'] = static function (): void {
    [$app, $log] = bootDispatchRegressionApplication(
        [
            DispatchRegressionOccurred::class => [
                DispatchRegressionBestEffortListener::class,
                DispatchRegressionSecondListener::class,
            ],
        ],
        reporter: new DispatchRegressionThrowingReporter(),
    );

    $app->make(Dispatcher::class)->dispatch(new DispatchRegressionOccurred('reporter'));

    assertEventSame(
        ['best-effort:reporter', 'second:reporter'],
        $log->entries,
        '上报器异常阻断了同步调用链',
    );
};

$tests['单个异步监听器生成一条含 trace 的事件总消息'] = static function (): void {
    [$app, $log] = bootDispatchRegressionApplication(
        [
            DispatchRegressionOccurred::class => [
                DispatchRegressionFirstQueuedListener::class,
            ],
        ],
        withDriver: true,
        traceIdProvider: new DispatchRegressionTraceIdProvider('trace-regression'),
    );
    $event = new DispatchRegressionOccurred('single');

    $app->make(Dispatcher::class)->dispatch($event);

    $driver = $app->make(Driver::class);
    assertEventTrue($driver instanceof DispatchRegressionDriver, '容器 Driver 类型错误');
    assertEventSame(['published'], $log->entries, '异步监听器在分发进程内被执行');
    assertEventSame(1, count($driver->messages), '单个异步监听器发布次数错误');
    assertEventSame($event, $driver->messages[0]->event, '消息未保留事件实例');
    assertEventSame('trace-regression', $driver->messages[0]->traceId, '消息未继承 trace');
    assertEventSame(
        [DispatchRegressionFirstQueuedListener::class],
        $driver->messages[0]->listeners,
        '消息监听器列表错误',
    );
};

$tests['多个异步监听器合并为一条有序消息'] = static function (): void {
    [$app] = bootDispatchRegressionApplication(
        [
            DispatchRegressionOccurred::class => [
                DispatchRegressionFirstQueuedListener::class,
                DispatchRegressionSecondQueuedListener::class,
            ],
        ],
        withDriver: true,
    );

    $app->make(Dispatcher::class)->dispatch(new DispatchRegressionOccurred('multiple'));

    $driver = $app->make(Driver::class);
    assertEventTrue($driver instanceof DispatchRegressionDriver, '容器 Driver 类型错误');
    assertEventSame(1, count($driver->messages), '多个异步监听器被拆成多条消息');
    assertEventSame(
        [
            DispatchRegressionFirstQueuedListener::class,
            DispatchRegressionSecondQueuedListener::class,
        ],
        $driver->messages[0]->listeners,
        '异步监听器顺序错误',
    );
};

$tests['同步监听器完成后才发布异步消息'] = static function (): void {
    [$app, $log] = bootDispatchRegressionApplication(
        [
            DispatchRegressionOccurred::class => [
                DispatchRegressionFirstQueuedListener::class,
                DispatchRegressionFirstListener::class,
                DispatchRegressionSecondQueuedListener::class,
                DispatchRegressionSecondListener::class,
            ],
        ],
        withDriver: true,
    );

    $app->make(Dispatcher::class)->dispatch(new DispatchRegressionOccurred('mixed'));

    assertEventSame(
        ['first:mixed', 'second:mixed', 'published'],
        $log->entries,
        '同步执行与异步发布顺序错误',
    );
};

$tests['同步监听器失败时不发布异步消息'] = static function (): void {
    [$app] = bootDispatchRegressionApplication(
        [
            DispatchRegressionOccurred::class => [
                DispatchRegressionFirstQueuedListener::class,
                DispatchRegressionThrowingListener::class,
            ],
        ],
        withDriver: true,
    );

    assertEventThrows(
        RuntimeException::class,
        static fn () => $app->make(Dispatcher::class)->dispatch(
            new DispatchRegressionOccurred('sync-failed'),
        ),
        '同步监听器失败',
        '同步失败未向上传播',
    );

    $driver = $app->make(Driver::class);
    assertEventTrue($driver instanceof DispatchRegressionDriver, '容器 Driver 类型错误');
    assertEventSame([], $driver->messages, '同步失败后仍发布了异步消息');
};
