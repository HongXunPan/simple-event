<?php

declare(strict_types=1);

use HongXunPan\Framework\Core\Application;
use HongXunPan\SimpleEvent\Consumer\Consumer;
use HongXunPan\SimpleEvent\Consumer\ReceivedMessage;
use HongXunPan\SimpleEvent\Event;
use HongXunPan\SimpleEvent\Execution\ErrorMessageSanitizer;
use HongXunPan\SimpleEvent\Execution\Failure;
use HongXunPan\SimpleEvent\Listener\ListenerFailureReporter;
use HongXunPan\SimpleEvent\Listener\ListenerInvoker;
use HongXunPan\SimpleEvent\Listener\ListenerRegistry;
use HongXunPan\SimpleEvent\Listener\ShouldHandleBestEffort;
use HongXunPan\SimpleEvent\Listener\ShouldQueue;
use HongXunPan\SimpleEvent\Message\EventMessage;
use HongXunPan\SimpleEvent\Serialization\Serializer;
use HongXunPan\SimpleEvent\Validation\EventValidator;
use HongXunPan\SimpleEvent\Validation\ListenerValidator;
use HongXunPan\SimpleEvent\Worker\EventMessageExecutor;
use HongXunPan\SimpleEvent\Worker\EventWorker;
use Throwable;

final readonly class WorkerRegressionOccurred implements Event
{
    public function __construct(public string $name)
    {
    }
}

final class WorkerRegressionLog
{
    /** @var list<string> */
    public array $entries = [];
}

final readonly class WorkerRegressionListener implements ShouldQueue
{
    public function __construct(private WorkerRegressionLog $log)
    {
    }

    public function handle(WorkerRegressionOccurred $event): void
    {
        $this->log->entries[] = $event->name;
    }
}

final readonly class WorkerRegressionFailingListener implements ShouldQueue
{
    public function __construct(private WorkerRegressionLog $log)
    {
    }

    public function handle(WorkerRegressionOccurred $event): void
    {
        $this->log->entries[] = 'failed:' . $event->name;
        throw new RuntimeException('消费失败');
    }
}

final readonly class WorkerRegressionSecondListener implements ShouldQueue
{
    public function __construct(private WorkerRegressionLog $log)
    {
    }

    public function handle(WorkerRegressionOccurred $event): void
    {
        $this->log->entries[] = 'second:' . $event->name;
    }
}

final readonly class WorkerRegressionBestEffortListener implements
    ShouldQueue,
    ShouldHandleBestEffort
{
    public function handle(WorkerRegressionOccurred $event): void
    {
        throw new RuntimeException('best-effort 消费失败');
    }
}

final class WorkerRegressionReporter implements ListenerFailureReporter
{
    /** @var list<class-string> */
    public array $listeners = [];

    public function report(string $listenerClass, Event $event, Throwable $throwable): void
    {
        $this->listeners[] = $listenerClass;
    }
}

final class WorkerRegressionConsumer implements Consumer
{
    /** @var list<ReceivedMessage> */
    public array $messages;

    /** @var list<ReceivedMessage> */
    public array $acknowledged = [];

    /** @var list<array{message: ReceivedMessage, failure: Failure}> */
    public array $failed = [];

    public int $receiveCalls = 0;

    public ?Throwable $receiveFailure = null;

    /** @param list<ReceivedMessage> $messages */
    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    public function receive(): iterable
    {
        ++$this->receiveCalls;
        if ($this->receiveFailure !== null) {
            throw $this->receiveFailure;
        }

        $messages = $this->messages;
        $this->messages = [];

        return $messages;
    }

    public function acknowledge(ReceivedMessage $message): void
    {
        $this->acknowledged[] = $message;
    }

    public function fail(ReceivedMessage $message, Failure $failure): void
    {
        $this->failed[] = compact('message', 'failure');
    }
}

final readonly class WorkerRegressionSerializer implements Serializer
{
    public function __construct(
        private ?EventMessage $eventMessage = null,
        private ?Throwable $failure = null,
    ) {
    }

    public function serialize(EventMessage $message): string
    {
        return 'test-payload';
    }

    public function deserialize(string $payload): EventMessage
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->eventMessage ?? throw new LogicException('测试 EventMessage 未配置');
    }
}

/**
 * @param list<ReceivedMessage>|null $messages
 * @return array{
 *     worker: EventWorker,
 *     consumer: WorkerRegressionConsumer,
 *     log: WorkerRegressionLog,
 *     reporter: WorkerRegressionReporter
 * }
 */
function createWorkerRegressionContext(
    Serializer $serializer,
    ?array $messages = null,
): array {
    $app = new Application();
    Application::setInstance($app);
    $log = new WorkerRegressionLog();
    $app->instance(WorkerRegressionLog::class, $log);

    $consumer = new WorkerRegressionConsumer(
        $messages ?? [new ReceivedMessage('message-1', 'test-payload')],
    );
    $errors = new ErrorMessageSanitizer();
    $reporter = new WorkerRegressionReporter();
    $events = new EventValidator();
    $registry = new ListenerRegistry($events, new ListenerValidator());
    foreach ([
        WorkerRegressionListener::class,
        WorkerRegressionFailingListener::class,
        WorkerRegressionSecondListener::class,
        WorkerRegressionBestEffortListener::class,
    ] as $listenerClass) {
        $registry->addListener(WorkerRegressionOccurred::class, $listenerClass);
    }

    $worker = new EventWorker(
        $consumer,
        $serializer,
        new EventMessageExecutor(new ListenerInvoker($app, $reporter), $errors),
        $events,
        $errors,
        $registry,
    );

    return compact('worker', 'consumer', 'log', 'reporter');
}

/**
 * @param list<class-string<ShouldQueue>> $listeners
 */
function makeWorkerRegressionMessage(array $listeners): EventMessage
{
    return new EventMessage(
        eventId: 'event-worker-regression',
        createdAt: new DateTimeImmutable(),
        event: new WorkerRegressionOccurred('contract'),
        listeners: $listeners,
        traceId: 'trace-worker-regression',
    );
}
