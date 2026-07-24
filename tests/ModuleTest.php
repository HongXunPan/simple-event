<?php

declare(strict_types=1);

use HongXunPan\SimpleEvent\EventModule;

$tests['Module 元数据、Provider、Helper 与发布资源'] = static function (): void {
    $module = new EventModule();
    assertEventSame('event', $module->name(), 'Module 名称错误');
    assertEventSame([], $module->requires(), 'Event Module 不应强制依赖 Redis Module');
    assertEventSame(null, $module->installer(), 'Event Module 不应存在安装期副作用');

    $composer = json_decode(
        (string)file_get_contents(dirname(__DIR__) . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    assertEventSame('simple-module', $composer['type'] ?? null, 'Composer 类型错误');
    assertEventSame(
        EventModule::class,
        $composer['extra']['simple']['module'] ?? null,
        'Composer Module 入口错误',
    );
    assertEventTrue(
        !isset($composer['require']['hongxunpan/simple-redis']),
        '同步 Event 不应强制安装 simple-redis',
    );
    assertEventTrue(
        isset($composer['suggest']['hongxunpan/simple-redis']),
        'Redis 可选依赖未通过 suggest 声明',
    );

    $helpers = require dirname(__DIR__) . '/config/helpers.php';
    assertEventSame(
        dirname(__DIR__) . '/src/helpers.php',
        $helpers['event'] ?? null,
        'event helper 所有权声明错误',
    );

    $resources = require dirname(__DIR__) . '/config/resources.php';
    assertEventSame(
        'config/events.php',
        $resources['config']['target'] ?? null,
        'Event 配置发布目标错误',
    );
};
