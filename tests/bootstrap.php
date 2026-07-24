<?php

declare(strict_types=1);

use HongXunPan\Framework\Config\Config;
use HongXunPan\Framework\Config\Env;
use HongXunPan\Framework\Core\Application;

final class SimpleEventTestFailure extends RuntimeException
{
}

/** @var array<string, Closure> */
$tests = [];

function assertEventTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new SimpleEventTestFailure($message);
    }
}

function assertEventSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new SimpleEventTestFailure(
            $message . '；预期=' . var_export($expected, true)
            . '，实际=' . var_export($actual, true),
        );
    }
}

/**
 * @param class-string<Throwable> $throwableClass
 */
function assertEventThrows(
    string $throwableClass,
    Closure $operation,
    string $messageContains,
    string $message,
): void {
    try {
        $operation();
    } catch (Throwable $throwable) {
        if (!$throwable instanceof $throwableClass) {
            throw new SimpleEventTestFailure(
                $message . '；异常类型=' . $throwable::class,
            );
        }
        if (!str_contains($throwable->getMessage(), $messageContains)) {
            throw new SimpleEventTestFailure(
                $message . '；异常信息=' . $throwable->getMessage(),
            );
        }

        return;
    }

    throw new SimpleEventTestFailure($message . '；未抛出异常');
}

/**
 * @param array<string, mixed> $configuration
 */
function makeEventApplication(array $configuration = []): Application
{
    $app = new Application();
    Application::setInstance($app);
    $app->instance(Config::class, Config::fromArray($configuration));
    $app->instance(Env::class, Env::fromArray(['APP_DEBUG' => false]));

    return $app;
}
