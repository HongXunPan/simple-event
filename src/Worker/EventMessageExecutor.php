<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Worker;

use DateTimeImmutable;
use HongXunPan\SimpleEvent\Execution\ErrorMessageSanitizer;
use HongXunPan\SimpleEvent\Execution\EventExecutionContext;
use HongXunPan\SimpleEvent\Execution\EventResult;
use HongXunPan\SimpleEvent\Execution\ListenerResult;
use HongXunPan\SimpleEvent\Listener\ListenerInvoker;
use HongXunPan\SimpleEvent\Message\EventMessage;
use Throwable;

final readonly class EventMessageExecutor
{
    public function __construct(
        private ListenerInvoker $invoker,
        private ErrorMessageSanitizer $errors,
        private EventExecutionContext $context,
    ) {
    }

    public function run(EventMessage $message): EventResult
    {
        $results = [];
        foreach ($message->listeners as $index => $listenerClass) {
            $startedAt = new DateTimeImmutable();
            $startedAtTick = hrtime(true);
            $this->context->enterListener($listenerClass);

            try {
                $this->invoker->invoke($listenerClass, $message->event);
                $results[] = new ListenerResult(
                    listenerClass: $listenerClass,
                    order: $index + 1,
                    startedAt: $startedAt,
                    finishedAt: new DateTimeImmutable(),
                    elapsedMs: $this->elapsedMs($startedAtTick),
                    succeeded: true,
                );
            } catch (Throwable $throwable) {
                $results[] = new ListenerResult(
                    listenerClass: $listenerClass,
                    order: $index + 1,
                    startedAt: $startedAt,
                    finishedAt: new DateTimeImmutable(),
                    elapsedMs: $this->elapsedMs($startedAtTick),
                    succeeded: false,
                    errorClass: $throwable::class,
                    errorMessage: $this->errors->sanitize($throwable->getMessage()),
                );
            } finally {
                $this->context->leaveListener();
            }
        }

        return new EventResult($results);
    }

    private function elapsedMs(int $startedAt): int
    {
        return max(0, (int)round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
