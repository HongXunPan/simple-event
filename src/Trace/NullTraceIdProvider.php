<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Trace;

final readonly class NullTraceIdProvider implements TraceIdProvider
{
    public function traceId(): ?string
    {
        return null;
    }
}
