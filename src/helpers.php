<?php

declare(strict_types=1);

use HongXunPan\SimpleEvent\Dispatch\Dispatcher;
use HongXunPan\SimpleEvent\Event;

if (!function_exists('event')) {
    function event(Event $event): void
    {
        app(Dispatcher::class)->dispatch($event);
    }
}
