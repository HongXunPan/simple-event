<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Config;

interface EventConfigLoader
{
    public function load(): EventConfig;
}
