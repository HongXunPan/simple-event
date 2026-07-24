<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Serialization;

use HongXunPan\SimpleEvent\Message\EventMessage;

interface Serializer
{
    public function serialize(EventMessage $message): string;

    public function deserialize(string $payload): EventMessage;
}
