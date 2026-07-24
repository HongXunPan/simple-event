<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Driver\Redis;

use HongXunPan\SimpleEvent\Config\DriverConfig;
use HongXunPan\SimpleEvent\Exception\EventConfigException;

final readonly class RedisStreamConfig
{
    private const array REQUIRED_KEYS = [
        'connection',
        'stream',
        'group',
        'failed_stream',
        'block_ms',
        'batch_size',
        'claim_idle_ms',
        'failed_max_length',
    ];

    public function __construct(
        public string $connection,
        public string $stream,
        public string $group,
        public string $failedStream,
        public int $blockMs,
        public int $batchSize,
        public int $claimIdleMs,
        public int $failedMaxLength,
    ) {
        if ($this->stream === $this->failedStream) {
            throw new EventConfigException('Redis Event stream 与 failed_stream 不能相同');
        }
    }

    public static function fromDriverConfig(DriverConfig $config): self
    {
        $options = $config->options();
        $unknownKeys = array_diff(array_keys($options), self::REQUIRED_KEYS);
        if ($unknownKeys !== []) {
            throw new EventConfigException(
                'Redis Event Driver 包含未知 option：' . implode('、', $unknownKeys),
            );
        }
        $missingKeys = array_diff(self::REQUIRED_KEYS, array_keys($options));
        if ($missingKeys !== []) {
            throw new EventConfigException(
                'Redis Event Driver 缺少 option：' . implode('、', $missingKeys),
            );
        }

        return new self(
            connection: self::nonEmptyString($options, 'connection'),
            stream: self::nonEmptyString($options, 'stream'),
            group: self::nonEmptyString($options, 'group'),
            failedStream: self::nonEmptyString($options, 'failed_stream'),
            blockMs: self::positiveInt($options, 'block_ms'),
            batchSize: self::positiveInt($options, 'batch_size'),
            claimIdleMs: self::positiveInt($options, 'claim_idle_ms'),
            failedMaxLength: self::positiveInt($options, 'failed_max_length'),
        );
    }

    /** @param array<string, mixed> $options */
    private static function nonEmptyString(array $options, string $key): string
    {
        $value = $options[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new EventConfigException("Redis Event Driver {$key} 必须是非空字符串");
        }

        return $value;
    }

    /** @param array<string, mixed> $options */
    private static function positiveInt(array $options, string $key): int
    {
        $value = $options[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new EventConfigException("Redis Event Driver {$key} 必须是正整数");
        }

        return $value;
    }
}
