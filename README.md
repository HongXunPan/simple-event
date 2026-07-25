# simple-event

`hongxunpan/simple-event` 是面向 `hongxunpan/simple-framework` 的事件 Module。

它提供：

- 同步监听器；
- 可选的 Redis Streams 异步监听器；
- 严格 Event 快照和持久化协议；
- best-effort 监听器；
- Worker、失败摘要与监听器白名单；
- framework 应用生命周期适配；
- Module 启用后加载的 `event()`。

同步模式不依赖 Redis。只有选择 `RedisStreamDriver` 时才需要安装并启用
`hongxunpan/simple-redis`。

## 安装与启用

```bash
composer require hongxunpan/simple-event
php bin/simple module:enable event
```

Composer 只负责安装包。`event()`、Provider 和生命周期适配只有在 Event Module 启用后才进入
应用运行时。

## 定义事件与监听器

### Event

Event 表达已经发生的业务事实快照：

```php
<?php

namespace App\Events;

use DateTimeImmutable;
use HongXunPan\SimpleEvent\Event;

final readonly class SampleOccurred implements Event
{
    public const int VERSION = 1;

    public function __construct(
        public int $sampleId,
        public int $userId,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
```

约束：

- Event 必须是 `final readonly`；
- 构造参数必须与公开实例属性一一对应；
- 未声明 `VERSION` 时默认为 `1`；
- 快照字段只允许标量、`null`、`BackedEnum` 和 `DateTimeImmutable`；
- 不允许携带 ORM Model、Request、Service、数组或任意对象。

### 监听器

同步监听器：

```php
<?php

namespace App\Listeners;

use App\Events\SampleOccurred;

final class WriteSampleAuditLog
{
    public function handle(SampleOccurred $event): void
    {
        // 写入示例审计事实。
    }
}
```

异步监听器实现 `ShouldQueue`：

```php
<?php

namespace App\Listeners;

use App\Events\SampleOccurred;
use HongXunPan\SimpleEvent\Listener\ShouldQueue;

final class SendSampleNotification implements ShouldQueue
{
    public function handle(SampleOccurred $event): void
    {
        // 异步副作用必须按业务唯一事实保证幂等。
    }
}
```

监听器的 `handle()` 必须：

- 是公开实例方法；
- 只接收一个参数；
- 参数类型精确等于配置中的 Event；
- 显式返回 `void`。

## 分发与消费

### 同步配置

项目 `config/events.php`：

```php
<?php

use App\Events\SampleOccurred;
use App\Listeners\WriteSampleAuditLog;

return [
    'listeners' => [
        SampleOccurred::class => [
            WriteSampleAuditLog::class,
        ],
    ],
];
```

触发：

```php
event(new SampleOccurred(
    sampleId: 1,
    userId: 10001,
    occurredAt: new DateTimeImmutable(),
));
```

同步监听器按配置顺序执行。普通同步监听器异常会原样向上传播，并停止后续监听器。

### best-effort

明确允许失败且不应污染调用链的监听器可以实现：

```php
use HongXunPan\SimpleEvent\Listener\ShouldHandleBestEffort;

final class OptionalListener implements ShouldHandleBestEffort
{
    public function handle(SampleOccurred $event): void
    {
    }
}
```

best-effort 异常会交给 `ListenerFailureReporter`，但不会阻断后续监听器。
项目可以通过 Provider 覆盖该契约。

### Redis Streams

安装并启用 Redis Module：

```bash
composer require hongxunpan/simple-redis
php bin/simple module:enable redis
php bin/simple module:enable event
```

`config/redis.php` 只保存连接配置：

```php
return [
    'connections' => [
        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => 0,
            'prefix' => 'app:',
        ],
    ],
];
```

`config/events.php` 保存 Event Driver 配置：

```php
<?php

use App\Events\SampleOccurred;
use App\Listeners\SendSampleNotification;
use HongXunPan\SimpleEvent\Driver\Redis\RedisStreamDriver;

return [
    'listeners' => [
        SampleOccurred::class => [
            SendSampleNotification::class,
        ],
    ],
    'driver' => [
        'class' => RedisStreamDriver::class,
        'options' => [
            'connection' => 'default',
            'stream' => 'business-events',
            'group' => 'application',
            'failed_stream' => 'business-events-failed',
            'block_ms' => 5000,
            'batch_size' => 10,
            'claim_idle_ms' => 60000,
            'failed_max_length' => 10000,
        ],
    ],
];
```

Redis host、认证、TLS、database 和 prefix 属于 Redis Module；stream、group、批次、
pending 回收和 failed stream 属于 Event Driver。两个配置边界不能混用。

### Worker

单轮消费：

```php
$processed = app(
    HongXunPan\SimpleEvent\Worker\EventWorker::class,
)->runOnce();
```

持续运行：

```php
app(HongXunPan\SimpleEvent\Worker\EventWorker::class)->run(
    static fn (): bool => $shouldStop,
);
```

信号处理、命令退出码和 Supervisor/systemd 配置由项目负责。

Redis Streams 使用 at-least-once 语义：

- ACK 前退出时整条事件消息可能重新执行；
- 所有异步监听器必须幂等；
- 普通异步监听器失败后，整条消息写入 failed stream；
- XACK 成功后，XDEL 失败只留下可清理残留，不重新执行消息；
- 消息内监听器必须是当前 Event 已登记异步监听器的子集。

## 项目覆盖

项目 Provider 可以覆盖：

- `EventConfigLoader`
- `TraceIdProvider`
- `ListenerFailureReporter`
- `Serializer`
- `Driver`

默认 Loader 只读取 `config/events.php`。若项目不希望使用 `events` 作为配置名称，应覆盖
`EventConfigLoader`，不要在包内增加旧键兼容或项目专用回退。

## 生命周期

Event Module 会把 framework 的：

- `RequestHandledSnapshot`
- `ExceptionOccurredSnapshot`

转换为 Event Module 自己的生命周期事件。framework core 不引用 `simple-event`，也不会直接调用
`event()`。

## 验证

```bash
composer test
```

共享工作区使用：

```bash
SIMPLE_EVENT_AUTOLOAD=/项目/vendor/autoload.php php tests/TestRunner.php
```

当前开发线要求 PHP `^8.5`。
