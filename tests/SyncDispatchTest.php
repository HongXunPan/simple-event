<?php

declare(strict_types=1);

use HongXunPan\SimpleEvent\Config\EventConfig;
use HongXunPan\SimpleEvent\Config\EventConfigLoader;
use HongXunPan\SimpleEvent\Dispatch\Dispatcher;
use HongXunPan\SimpleEvent\Driver\Driver;
use HongXunPan\SimpleEvent\Event;
use HongXunPan\SimpleEvent\EventServiceProvider;
use HongXunPan\SimpleEvent\Exception\EventConfigException;
use HongXunPan\SimpleEvent\Listener\ListenerFailureReporter;
use HongXunPan\SimpleEvent\Listener\ShouldHandleBestEffort;
use HongXunPan\SimpleEvent\Listener\ShouldQueue;
use Throwable;

final readonly class SampleOccurred implements Event
{
    public function __construct(public string $name)
    {
    }
}

final class FirstSampleListener
{
    /** @var list<string> */
    public static array $calls = [];

    public function handle(SampleOccurred $event): void
    {
        self::$calls[] = 'first:' . $event->name;
    }
}

final class SecondSampleListener
{
    public function handle(SampleOccurred $event): void
    {
        FirstSampleListener::$calls[] = 'second:' . $event->name;
    }
}

final class BestEffortSampleListener implements ShouldHandleBestEffort
{
    public function handle(SampleOccurred $event): void
    {
        throw new RuntimeException('可降级监听器失败');
    }
}

final class QueuedSampleListener implements ShouldQueue
{
    public function handle(SampleOccurred $event): void
    {
    }
}

final class RecordingSampleFailureReporter implements ListenerFailureReporter
{
    /** @var list<Throwable> */
    public array $failures = [];

    public function report(string $listenerClass, Event $event, Throwable $throwable): void
    {
        $this->failures[] = $throwable;
    }
}

final readonly class FixedEventConfigLoader implements EventConfigLoader
{
    public function load(): EventConfig
    {
        return EventConfig::fromArray([
            'listeners' => [
                SampleOccurred::class => [FirstSampleListener::class],
            ],
        ]);
    }
}

$tests['纯同步 Provider 按配置顺序分发且不绑定 Driver'] = static function (): void {
    FirstSampleListener::$calls = [];
    $app = makeEventApplication([
        'events' => [
            'listeners' => [
                SampleOccurred::class => [
                    FirstSampleListener::class,
                    SecondSampleListener::class,
                ],
            ],
        ],
    ]);
    $provider = new EventServiceProvider();
    $provider->register($app);
    $provider->boot($app);

    $app->make(Dispatcher::class)->dispatch(new SampleOccurred('sample'));

    assertEventSame(
        ['first:sample', 'second:sample'],
        FirstSampleListener::$calls,
        '同步监听器执行顺序错误',
    );
    assertEventTrue(!$app->bound(Driver::class), '纯同步配置错误绑定了 Driver');
};

$tests['项目可以覆盖 EventConfigLoader'] = static function (): void {
    FirstSampleListener::$calls = [];
    $app = makeEventApplication();
    $app->instance(EventConfigLoader::class, new FixedEventConfigLoader());
    $provider = new EventServiceProvider();
    $provider->register($app);
    $provider->boot($app);

    $app->make(Dispatcher::class)->dispatch(new SampleOccurred('override'));

    assertEventSame(
        ['first:override'],
        FirstSampleListener::$calls,
        '自定义 EventConfigLoader 未生效',
    );
};

$tests['best-effort 同步失败上报后继续执行'] = static function (): void {
    FirstSampleListener::$calls = [];
    $reporter = new RecordingSampleFailureReporter();
    $app = makeEventApplication([
        'events' => [
            'listeners' => [
                SampleOccurred::class => [
                    BestEffortSampleListener::class,
                    FirstSampleListener::class,
                ],
            ],
        ],
    ]);
    $app->instance(ListenerFailureReporter::class, $reporter);
    $provider = new EventServiceProvider();
    $provider->register($app);
    $provider->boot($app);

    $app->make(Dispatcher::class)->dispatch(new SampleOccurred('continue'));

    assertEventSame(1, count($reporter->failures), 'best-effort 失败未上报');
    assertEventSame(
        ['first:continue'],
        FirstSampleListener::$calls,
        'best-effort 失败阻断了后续监听器',
    );
};

$tests['存在 ShouldQueue 监听器但未配置 Driver 时启动失败'] = static function (): void {
    $app = makeEventApplication([
        'events' => [
            'listeners' => [
                SampleOccurred::class => [QueuedSampleListener::class],
            ],
        ],
    ]);
    $provider = new EventServiceProvider();
    $provider->register($app);

    assertEventThrows(
        EventConfigException::class,
        static fn () => $provider->boot($app),
        '必须配置 Event Driver',
        '缺少 Driver 时仍启动了异步监听器',
    );
};
