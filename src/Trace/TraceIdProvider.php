<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Trace;

interface TraceIdProvider
{
    public function traceId(): ?string;
}
