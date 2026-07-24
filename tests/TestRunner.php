<?php

declare(strict_types=1);

$autoload = getenv('SIMPLE_EVENT_AUTOLOAD');
if (!is_string($autoload) || $autoload === '') {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
}
require $autoload;
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/ModuleTest.php';
require __DIR__ . '/ConfigTest.php';
require __DIR__ . '/SyncDispatchTest.php';
require __DIR__ . '/DispatchRegressionTest.php';
require __DIR__ . '/ProviderValidationTest.php';
require __DIR__ . '/LifecycleTest.php';
require __DIR__ . '/SerializationTest.php';
require __DIR__ . '/SerializationRegressionTest.php';
require __DIR__ . '/WorkerWhitelistTest.php';
require __DIR__ . '/WorkerRegressionTest.php';
require __DIR__ . '/RedisDriverTest.php';
require __DIR__ . '/RedisRegressionTest.php';

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "[通过] {$name}\n");
    } catch (Throwable $throwable) {
        $failed++;
        fwrite(STDERR, "[失败] {$name}：{$throwable->getMessage()}\n");
    }
}

if ($failed > 0) {
    fwrite(STDERR, "simple-event 测试失败：{$failed} 项\n");
    exit(1);
}

fwrite(STDOUT, 'simple-event 测试通过：' . count($tests) . " 项\n");
