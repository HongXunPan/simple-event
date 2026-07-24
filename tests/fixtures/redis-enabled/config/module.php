<?php

declare(strict_types=1);

use HongXunPan\SimpleEvent\EventModule;
use HongXunPan\SimpleRedis\RedisModule;

return [
    'enable' => [
        EventModule::class,
        RedisModule::class,
    ],
    'provider-override' => [
    ],
];
