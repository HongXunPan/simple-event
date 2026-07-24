<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent;

use HongXunPan\Framework\Module\Module;

final class EventModule implements Module
{
    public function name(): string
    {
        return 'event';
    }

    public function basePath(): string
    {
        return dirname(__DIR__);
    }

    public function requires(): array
    {
        return [];
    }

    public function installer(): ?string
    {
        return null;
    }
}
