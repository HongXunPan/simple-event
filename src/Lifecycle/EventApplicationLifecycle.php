<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Lifecycle;

use HongXunPan\Framework\Lifecycle\ApplicationLifecycle;
use HongXunPan\Framework\Lifecycle\ExceptionOccurredSnapshot;
use HongXunPan\Framework\Lifecycle\RequestHandledSnapshot;
use HongXunPan\SimpleEvent\Dispatch\Dispatcher;
use HongXunPan\SimpleEvent\Execution\ErrorMessageSanitizer;

final readonly class EventApplicationLifecycle implements ApplicationLifecycle
{
    public function __construct(
        private Dispatcher $events,
        private ErrorMessageSanitizer $errors,
    ) {
    }

    public function requestHandled(RequestHandledSnapshot $snapshot): void
    {
        $this->events->dispatch(new RequestHandled());
    }

    public function exceptionOccurred(ExceptionOccurredSnapshot $snapshot): void
    {
        $throwable = $snapshot->throwable;
        $this->events->dispatch(new ExceptionOccurred(
            exceptionClass: $throwable::class,
            message: $this->errors->sanitize($throwable->getMessage()),
            code: $throwable->getCode(),
        ));
    }
}
