<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Config;

use HongXunPan\Framework\Config\Config;
use RuntimeException;

final readonly class DefaultEventConfigLoader implements EventConfigLoader
{
    public function __construct(private Config $config)
    {
    }

    public function load(): EventConfig
    {
        $configuration = $this->config->get('events', null);
        if ($configuration === null) {
            $configuration = require dirname(__DIR__, 2) . '/resources/config/events.php';
        }
        if (!is_array($configuration)) {
            throw new RuntimeException('config/events.php 必须返回配置数组');
        }

        return EventConfig::fromArray($configuration);
    }
}
