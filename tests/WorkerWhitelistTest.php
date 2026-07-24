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
use HongXunPan\SimpleEvent\Listener\ShouldQueue;
use HongXunPan\SimpleEvent\Message\EventMessage;
use HongXunPan\SimpleEvent\Serialization\Serializer;
use HongXunPan\SimpleEvent\Worker\EventWorker;

final readonly class WorkerSampleOccurred implements Event
{
    public function __construct(public string $name)
    {
    }
}

final class RegisteredWorkerListener implements ShouldQueue
{
    public static int $calls = 0;

    public function handle(WorkerSampleOccurred $event): void
    {
        self::$calls++;
    }
}

final class InjectedWorkerListener implements ShouldQueue
{
    public static int $calls = 0;

    public function handle(WorkerSampleOccurred $event): void
    {
        self::$calls++;
    }
}

final class WorkerTestConsumer implements Consumer
{
    /** @var list<ReceivedMessage> */
    public array $messages = [];

    /** @var list<ReceivedMessage> */
    public array $acknowledged = [];

    /** @var list<Failure> */
    public array $failures = [];

    public function receive(): iterable
    {
        return $this->messages;
    }

    public function acknowledge(ReceivedMessage $message): void
    {
        $this->acknowledged[] = $message;
    }

    public function fail(ReceivedMessage $message, Failure $failure): void
    {
        $this->failures[] = $failure;
    }
}

final readonly class WorkerTestDriver implements Driver
{
    public static function validateConfig(DriverConfig $config, Application $app): void
    {
    }

    public static function consumerClass(): string
    {
        return WorkerTestConsumer::class;
    }

    public function publish(EventMessage $message): void
    {
    }
}

/**
 * @return array{Application, WorkerTestConsumer}
 */
function bootWorkerTestApplication(): array
{
    $consumer = new WorkerTestConsumer();
    $app = makeEventApplication([
        'events' => [
            'listeners' => [
                WorkerSampleOccurred::class => [RegisteredWorkerListener::class],
            ],
            'driver' => [
                'class' => WorkerTestDriver::class,
                'options' => [],
            ],
        ],
    ]);
    $app->instance(Consumer::class, $consumer);
    $provider = new EventServiceProvider();
    $provider->register($app);
    $provider->boot($app);

    return [$app, $consumer];
}

$tests['Worker 只执行当前 Event 已注册的异步监听器'] = static function (): void {
    RegisteredWorkerListener::$calls = 0;
    InjectedWorkerListener::$calls = 0;
    [$app, $consumer] = bootWorkerTestApplication();
    $message = new EventMessage(
        eventId: 'injected-message',
        createdAt: new DateTimeImmutable(),
        event: new WorkerSampleOccurred('injected'),
        listeners: [InjectedWorkerListener::class],
    );
    $consumer->messages = [new ReceivedMessage(
        '1-0',
        $app->make(Serializer::class)->serialize($message),
    )];

    $processed = $app->make(EventWorker::class)->runOnce();

    assertEventSame(1, $processed, 'Worker 处理数量错误');
    assertEventSame(0, InjectedWorkerListener::$calls, 'Worker 执行了未注册监听器');
    assertEventSame([], $consumer->acknowledged, '未注册监听器消息被错误确认');
    assertEventSame(1, count($consumer->failures), '未注册监听器消息未进入失败处理');
    assertEventTrue(
        str_contains($consumer->failures[0]->errorMessage ?? '', '未注册'),
        '失败摘要未说明 listener 白名单问题',
    );
};

$tests['Worker 执行已注册异步监听器并确认消息'] = static function (): void {
    RegisteredWorkerListener::$calls = 0;
    [$app, $consumer] = bootWorkerTestApplication();
    $message = new EventMessage(
        eventId: 'registered-message',
        createdAt: new DateTimeImmutable(),
        event: new WorkerSampleOccurred('registered'),
        listeners: [RegisteredWorkerListener::class],
    );
    $consumer->messages = [new ReceivedMessage(
        '2-0',
        $app->make(Serializer::class)->serialize($message),
    )];

    $app->make(EventWorker::class)->runOnce();

    assertEventSame(1, RegisteredWorkerListener::$calls, '已注册监听器未执行');
    assertEventSame(1, count($consumer->acknowledged), '成功消息未确认');
    assertEventSame([], $consumer->failures, '成功消息错误进入失败处理');
};
