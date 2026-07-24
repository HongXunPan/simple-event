<?php

declare(strict_types=1);

use HongXunPan\SimpleEvent\Consumer\ReceivedMessage;

require_once __DIR__ . '/Support/WorkerRegressionSupport.php';

$tests['EventWorker 只依赖 Consumer 契约完成消息确认'] = static function (): void {
    $context = createWorkerRegressionContext(new WorkerRegressionSerializer(
        makeWorkerRegressionMessage([WorkerRegressionListener::class]),
    ));

    assertEventSame(1, $context['worker']->runOnce(), 'Worker 消费数量错误');
    assertEventSame(['contract'], $context['log']->entries, 'Worker 未执行消息监听器');
    assertEventSame(
        ['message-1'],
        array_column($context['consumer']->acknowledged, 'id'),
        '成功消息未确认',
    );
    assertEventSame([], $context['consumer']->failed, '成功消息进入失败流程');
    assertEventSame(0, $context['worker']->runOnce(), 'Consumer 清空后仍重复消费');
};

$tests['EventWorker 启动前收到停止条件时不领取消息'] = static function (): void {
    $context = createWorkerRegressionContext(new WorkerRegressionSerializer(
        makeWorkerRegressionMessage([WorkerRegressionListener::class]),
    ));

    $context['worker']->run(static fn (): bool => true);

    assertEventSame(0, $context['consumer']->receiveCalls, '停止条件成立后仍领取消息');
    assertEventSame([], $context['consumer']->acknowledged, '停止条件成立后仍确认消息');
};

$tests['EventWorker 只在完整批次之间停止'] = static function (): void {
    $context = createWorkerRegressionContext(
        new WorkerRegressionSerializer(
            makeWorkerRegressionMessage([WorkerRegressionListener::class]),
        ),
        [
            new ReceivedMessage('message-1', 'test-payload'),
            new ReceivedMessage('message-2', 'test-payload'),
        ],
    );
    $checks = 0;

    $context['worker']->run(static function () use (&$checks): bool {
        return $checks++ > 0;
    });

    assertEventSame(1, $context['consumer']->receiveCalls, 'Worker 停止前消费批次数错误');
    assertEventSame(
        ['message-1', 'message-2'],
        array_column($context['consumer']->acknowledged, 'id'),
        'Worker 在批次内部中断消息执行',
    );
    assertEventSame(
        ['contract', 'contract'],
        $context['log']->entries,
        '批次内监听器未全部执行',
    );
};

$tests['EventWorker 不吞没 Consumer 运行异常'] = static function (): void {
    $context = createWorkerRegressionContext(new WorkerRegressionSerializer(
        makeWorkerRegressionMessage([WorkerRegressionListener::class]),
    ));
    $context['consumer']->receiveFailure = new RuntimeException('Consumer 读取失败');

    assertEventThrows(
        RuntimeException::class,
        static fn () => $context['worker']->runOnce(),
        'Consumer 读取失败',
        'Worker 吞没或改写了 Consumer 异常',
    );
};

$tests['EventWorker 将监听器失败详情交给 Consumer'] = static function (): void {
    $context = createWorkerRegressionContext(new WorkerRegressionSerializer(
        makeWorkerRegressionMessage([
            WorkerRegressionListener::class,
            WorkerRegressionFailingListener::class,
            WorkerRegressionSecondListener::class,
        ]),
    ));

    assertEventSame(1, $context['worker']->runOnce(), '失败消息未计入消费数量');
    assertEventSame([], $context['consumer']->acknowledged, '失败消息被直接确认');
    assertEventSame(1, count($context['consumer']->failed), '失败消息未交给 Consumer');
    assertEventSame(
        ['contract', 'failed:contract', 'second:contract'],
        $context['log']->entries,
        '失败监听器阻断了后续监听器',
    );

    $failure = $context['consumer']->failed[0]['failure'];
    assertEventSame('message-1', $failure->messageId, 'Failure 消息 ID 错误');
    assertEventSame('trace-worker-regression', $failure->traceId, 'Failure 缺少 trace ID');
    assertEventSame(
        [true, false, true],
        array_column($failure->listeners, 'succeeded'),
        'Failure 缺少逐监听器结果',
    );
    $payload = $failure->toArray();
    assertEventSame(
        ['succeeded', 'failed', 'succeeded'],
        array_column($payload['listeners'], 'status'),
        '监听器终态错误',
    );
    assertEventTrue($payload['message_created_at'] !== null, 'Failure 缺少消息创建时间');
    assertEventTrue(
        !empty($payload['listeners'][1]['started_at']),
        'Failure 缺少监听器开始时间',
    );
    assertEventTrue(
        !empty($payload['listeners'][1]['finished_at']),
        'Failure 缺少监听器结束时间',
    );
};

$tests['EventWorker 确认 best-effort 失败消息并完成上报'] = static function (): void {
    $context = createWorkerRegressionContext(new WorkerRegressionSerializer(
        makeWorkerRegressionMessage([WorkerRegressionBestEffortListener::class]),
    ));

    assertEventSame(1, $context['worker']->runOnce(), 'best-effort 消息未计入消费数量');
    assertEventSame(
        ['message-1'],
        array_column($context['consumer']->acknowledged, 'id'),
        'best-effort 失败消息未确认',
    );
    assertEventSame([], $context['consumer']->failed, 'best-effort 消息进入失败流程');
    assertEventSame(
        [WorkerRegressionBestEffortListener::class],
        $context['reporter']->listeners,
        'best-effort 异常未按监听器上报',
    );
};

$tests['EventWorker 将反序列化失败摘要交给 Consumer'] = static function (): void {
    $context = createWorkerRegressionContext(new WorkerRegressionSerializer(
        failure: new RuntimeException('token=secret mobile=13800138000'),
    ));

    assertEventSame(1, $context['worker']->runOnce(), '反序列化失败消息未计数');
    $failure = $context['consumer']->failed[0]['failure'];
    assertEventSame(RuntimeException::class, $failure->errorClass, '异常类型未保留');
    assertEventSame(
        'token=[REDACTED] mobile=1**********',
        $failure->errorMessage,
        '反序列化异常未清洗敏感信息',
    );
    assertEventSame('message-1', $failure->messageId, 'Failure 消息 ID 错误');
};
