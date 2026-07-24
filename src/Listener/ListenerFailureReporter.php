<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Listener;

use HongXunPan\SimpleEvent\Event;
use Throwable;

interface ListenerFailureReporter
{
    /** @param class-string $listenerClass */
    public function report(string $listenerClass, Event $event, Throwable $throwable): void;
}
