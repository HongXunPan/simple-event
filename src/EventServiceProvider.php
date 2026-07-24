<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent;

use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Lifecycle\ApplicationLifecycle;
use HongXunPan\Framework\Provider\ServiceProvider;
use HongXunPan\SimpleEvent\Config\DefaultEventConfigLoader;
use HongXunPan\SimpleEvent\Config\DriverConfig;
use HongXunPan\SimpleEvent\Config\EventConfig;
use HongXunPan\SimpleEvent\Config\EventConfigLoader;
use HongXunPan\SimpleEvent\Consumer\Consumer;
use HongXunPan\SimpleEvent\Dispatch\Dispatcher;
use HongXunPan\SimpleEvent\Driver\Driver;
use HongXunPan\SimpleEvent\Exception\EventConfigException;
use HongXunPan\SimpleEvent\Execution\ErrorMessageSanitizer;
use HongXunPan\SimpleEvent\Lifecycle\EventApplicationLifecycle;
use HongXunPan\SimpleEvent\Listener\ErrorLogListenerFailureReporter;
use HongXunPan\SimpleEvent\Listener\ListenerFailureReporter;
use HongXunPan\SimpleEvent\Listener\ListenerInvoker;
use HongXunPan\SimpleEvent\Listener\ListenerRegistry;
use HongXunPan\SimpleEvent\Message\EventMessageFactory;
use HongXunPan\SimpleEvent\Serialization\Serializer;
use HongXunPan\SimpleEvent\Serialization\SymfonySerializer;
use HongXunPan\SimpleEvent\Trace\NullTraceIdProvider;
use HongXunPan\SimpleEvent\Trace\TraceIdProvider;
use HongXunPan\SimpleEvent\Validation\EventValidator;
use HongXunPan\SimpleEvent\Validation\ListenerValidator;
use HongXunPan\SimpleEvent\Worker\EventMessageExecutor;
use HongXunPan\SimpleEvent\Worker\EventWorker;
use Throwable;

final class EventServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $app->singletonIf(EventConfigLoader::class, DefaultEventConfigLoader::class);
        $app->singletonIf(
            EventConfig::class,
            static fn (Application $application): EventConfig =>
                $application->make(EventConfigLoader::class)->load(),
        );
        $app->singletonIf(EventValidator::class);
        $app->singletonIf(ListenerValidator::class);
        $app->singletonIf(ErrorMessageSanitizer::class);
        $app->singletonIf(
            ListenerFailureReporter::class,
            ErrorLogListenerFailureReporter::class,
        );
        $app->singletonIf(
            ListenerInvoker::class,
            static fn (Application $application): ListenerInvoker => new ListenerInvoker(
                $application,
                $application->make(ListenerFailureReporter::class),
            ),
        );
        $app->singletonIf(ListenerRegistry::class);
        $app->singletonIf(TraceIdProvider::class, NullTraceIdProvider::class);
        $app->singletonIf(EventMessageFactory::class);
        $app->singletonIf(Serializer::class, SymfonySerializer::class);
        $app->singleton(ApplicationLifecycle::class, EventApplicationLifecycle::class);
    }

    public function boot(Application $app): void
    {
        $config = $app->make(EventConfig::class);
        if ($config->driver === null && $config->hasQueuedListeners()) {
            throw new EventConfigException('存在 ShouldQueue 监听器时必须配置 Event Driver');
        }

        if ($config->driver !== null) {
            $this->registerDriver($app, $config->driver);
        }

        $app->singletonIf(
            Dispatcher::class,
            static fn (Application $application): Dispatcher => new Dispatcher(
                $application->make(ListenerRegistry::class),
                $application->make(ListenerInvoker::class),
                $application->make(EventMessageFactory::class),
                $application->bound(Driver::class)
                    ? $application->make(Driver::class)
                    : null,
            ),
        );

        $dispatcher = $app->make(Dispatcher::class);
        foreach ($config->listeners() as $eventClass => $listeners) {
            foreach ($listeners as $listenerClass) {
                $dispatcher->addListener($eventClass, $listenerClass);
            }
        }
    }

    private function registerDriver(Application $app, DriverConfig $config): void
    {
        $driverClass = $config->class;
        try {
            $consumerClass = $driverClass::consumerClass();
        } catch (Throwable $throwable) {
            throw new EventConfigException(
                "Event Driver 无法声明 Consumer：{$driverClass}",
                previous: $throwable,
            );
        }
        if (!class_exists($consumerClass) || !is_a($consumerClass, Consumer::class, true)) {
            throw new EventConfigException(
                "Event Driver 的 consumerClass() 必须返回 Consumer 类：{$driverClass}",
            );
        }

        $driverClass::validateConfig($config, $app);
        $app->instance(DriverConfig::class, $config);
        $app->singletonIf(Driver::class, $driverClass);
        $app->singletonIf(Consumer::class, $consumerClass);
        $app->singletonIf(EventMessageExecutor::class);
        $app->singletonIf(EventWorker::class);
    }
}
