<?php

declare(strict_types=1);

use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Core\Request;
use HongXunPan\SimpleEvent\Execution\EventExecutionContext;
use HongXunPan\SimpleEvent\Trace\FrameworkRequestTraceIdProvider;

require_once __DIR__ . '/Support/WorkerRegressionSupport.php';

$tests['Framework Trace Provider 在 Worker 中沿用事件 trace'] = static function (): void {
    $app = new Application();
    Application::setInstance($app);
    $app->isCli = true;
    $context = new EventExecutionContext();
    $context->beginMessage('message-trace');
    $context->attachEventMessage(makeWorkerRegressionMessage([
        WorkerRegressionListener::class,
    ]));

    $provider = new FrameworkRequestTraceIdProvider($app, $context);
    assertEventSame(
        'trace-worker-regression',
        $provider->traceId(),
        'Worker 内再次发布 Event 时未沿用当前 trace',
    );

    $context->clear();
    assertEventSame(null, $provider->traceId(), 'CLI 空上下文不应生成 trace');
};

$tests['Framework Trace Provider 在 HTTP 中沿用 request ID'] = static function (): void {
    $app = new Application();
    Application::setInstance($app);
    $app->isCli = false;
    $request = new Request([
        'server' => ['request_uri' => '/trace'],
        'ip' => '127.0.0.1',
        'headers' => [],
        'get' => [],
        'post' => [],
        'cookie' => [],
        'files' => [],
    ]);
    $request->requestId = 'request-trace';
    $app->instance(Request::class, $request);

    $provider = new FrameworkRequestTraceIdProvider(
        $app,
        new EventExecutionContext(),
    );
    assertEventSame(
        'request-trace',
        $provider->traceId(),
        'HTTP 发布 Event 时未沿用 request ID',
    );
};
