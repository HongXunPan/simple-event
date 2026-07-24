<?php

declare(strict_types=1);

use HongXunPan\Framework\Core\Application;
use HongXunPan\SimpleEvent\Config\DriverConfig;
use HongXunPan\SimpleEvent\Consumer\Consumer;
use HongXunPan\SimpleEvent\Consumer\ReceivedMessage;
use HongXunPan\SimpleEvent\Driver\Driver;
use HongXunPan\SimpleEvent\Event;
use HongXunPan\SimpleEvent\EventServiceProvider;
use HongXunPan\SimpleEvent\Execution\Failure;
use HongXunPan\SimpleEvent\Listener\ListenerFailureReporter;
use HongXunPan\SimpleEvent\Listener\ShouldHandleBestEffort;
use HongXunPan\SimpleEvent\Listener\ShouldQueue;
use HongXunPan\SimpleEvent\Message\EventMessage;
use HongXunPan\SimpleEvent\Trace\TraceIdProvider;
use Throwable;

final readonly class DispatchRegressionOccurred implements Event
{
    public function __construct(public string $name)
    {
    }
}

final class DispatchRegressionLog
{
    /** @var list<string> */
    public array $entries = [];
}

final readonly class DispatchRegressionFirstListener
{
    public function __construct(private DispatchRegressionLog $log)
    {
    }

    public function handle(DispatchRegressionOccurred $event): void
    {
        $this->log->entries[] = 'first:' . $event->name;
    }
}

final readonly class DispatchRegressionSecondListener
{
    public function __construct(private DispatchRegressionLog $log)
    {
    }

    public function handle(DispatchRegressionOccurred $event): void
    {
        $this->log->entries[] = 'second:' . $event->name;
    }
}

final readonly class DispatchRegressionThrowingListener
{
    public function __construct(private DispatchRegressionLog $log)
    {
    }

    public function handle(DispatchRegressionOccurred $event): void
    {
        $this->log->entries[] = 'throwing:' . $event->name;
        throw new RuntimeException('同步监听器失败');
    }
}

final readonly class DispatchRegressionBestEffortListener implements ShouldHandleBestEffort
{
    public function __construct(private DispatchRegressionLog $log)
    {
    }

    public function handle(DispatchRegressionOccurred $event): void
    {
        $this->log->entries[] = 'best-effort:' . $event->name;
        throw new RuntimeException('可降级监听器失败');
    }
}

final readonly class DispatchRegressionFirstQueuedListener implements ShouldQueue
{
    public function __construct(private DispatchRegressionLog $log)
    {
    }

    public function handle(DispatchRegressionOccurred $event): void
    {
        $this->log->entries[] = 'queued-first:' . $event->name;
    }
}

final readonly class DispatchRegressionSecondQueuedListener implements ShouldQueue
{
    public function __construct(private DispatchRegressionLog $log)
    {
    }

    public function handle(DispatchRegressionOccurred $event): void
    {
        $this->log->entries[] = 'queued-second:' . $event->name;
    }
}

final readonly class DispatchRegressionThrowingReporter implements ListenerFailureReporter
{
    public function report(string $listenerClass, Event $event, Throwable $throwable): void
    {
        throw new RuntimeException('上报器失败');
    }
}

final readonly class DispatchRegressionTraceIdProvider implements TraceIdProvider
{
    public function __construct(private string $traceId)
    {
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }
}

final class DispatchRegressionDriver implements Driver
{
    /** @var list<EventMessage> */
    public array $messages = [];

    public function __construct(private readonly DispatchRegressionLog $log)
    {
    }

    public static function validateConfig(DriverConfig $config, Application $app): void
    {
    }

    public static function consumerClass(): string
    {
        return DispatchRegressionConsumer::class;
    }

    public function publish(EventMessage $message): void
    {
        $this->log->entries[] = 'published';
        $this->messages[] = $message;
    }
}

final readonly class DispatchRegressionConsumer implements Consumer
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

/**
 * @param array<class-string<Event>, list<class-string>> $listeners
 * @return array{Application, DispatchRegressionLog}
 */
function bootDispatchRegressionApplication(
    array $listeners = [],
    bool $withEventsConfig = true,
    bool $withDriver = false,
    ?ListenerFailureReporter $reporter = null,
    ?TraceIdProvider $traceIdProvider = null,
): array {
    $configuration = [];
    if ($withEventsConfig) {
        $configuration['events'] = ['listeners' => $listeners];
        if ($withDriver) {
            $configuration['events']['driver'] = [
                'class' => DispatchRegressionDriver::class,
                'options' => [],
            ];
        }
    }

    $app = makeEventApplication($configuration);
    $log = new DispatchRegressionLog();
    $app->instance(DispatchRegressionLog::class, $log);
    if ($reporter !== null) {
        $app->instance(ListenerFailureReporter::class, $reporter);
    }
    if ($traceIdProvider !== null) {
        $app->instance(TraceIdProvider::class, $traceIdProvider);
    }

    $provider = new EventServiceProvider();
    $provider->register($app);
    $provider->boot($app);

    return [$app, $log];
}
