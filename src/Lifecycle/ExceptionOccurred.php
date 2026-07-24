<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Lifecycle;

use HongXunPan\SimpleEvent\Event;

final readonly class ExceptionOccurred implements Event
{
    public function __construct(
        public string $exceptionClass,
        public string $message,
        public int $code,
    ) {
    }
}
