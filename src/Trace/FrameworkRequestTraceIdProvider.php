<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Trace;

use HongXunPan\Framework\Core\Application;
use HongXunPan\Framework\Core\Request;
use RuntimeException;

final readonly class FrameworkRequestTraceIdProvider implements TraceIdProvider
{
    public function __construct(private Application $app)
    {
    }

    public function traceId(): ?string
    {
        if (!$this->app->bound(Request::class)) {
            return null;
        }

        $request = $this->app->make(Request::class);
        if (!$request instanceof Request) {
            throw new RuntimeException('Request 容器绑定类型错误');
        }

        return $request->requestId === '' ? null : $request->requestId;
    }
}
