<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Driver;

use HongXunPan\Framework\Core\Application;
use HongXunPan\SimpleEvent\Config\DriverConfig;
use HongXunPan\SimpleEvent\Consumer\Consumer;
use HongXunPan\SimpleEvent\Message\EventMessage;

interface Driver
{
    public static function validateConfig(DriverConfig $config, Application $app): void;

    /** @return class-string<Consumer> */
    public static function consumerClass(): string;

    public function publish(EventMessage $message): void;
}
