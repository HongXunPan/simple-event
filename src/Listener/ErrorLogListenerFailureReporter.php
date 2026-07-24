<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Listener;

use HongXunPan\SimpleEvent\Event;
use HongXunPan\SimpleEvent\Execution\ErrorMessageSanitizer;
use Throwable;

final readonly class ErrorLogListenerFailureReporter implements ListenerFailureReporter
{
    public function __construct(private ErrorMessageSanitizer $errors)
    {
    }

    public function report(string $listenerClass, Event $event, Throwable $throwable): void
    {
        $context = json_encode([
            'listener_class' => $listenerClass,
            'event_class' => $event::class,
            'exception_class' => $throwable::class,
            'error_message' => $this->errors->sanitize($throwable->getMessage()),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        error_log('[simple-event:best-effort] ' . ($context ?: '{}'));
    }
}
