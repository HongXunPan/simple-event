<?php

declare(strict_types=1);

namespace HongXunPan\SimpleEvent\Worker;

use DateTimeImmutable;
use HongXunPan\SimpleEvent\Consumer\Consumer;
use HongXunPan\SimpleEvent\Consumer\ReceivedMessage;
use HongXunPan\SimpleEvent\Exception\EventConsumeException;
use HongXunPan\SimpleEvent\Execution\ErrorMessageSanitizer;
use HongXunPan\SimpleEvent\Execution\EventResult;
use HongXunPan\SimpleEvent\Execution\Failure;
use HongXunPan\SimpleEvent\Listener\ListenerRegistry;
use HongXunPan\SimpleEvent\Message\EventMessage;
use HongXunPan\SimpleEvent\Serialization\Serializer;
use HongXunPan\SimpleEvent\Validation\EventValidator;
use Throwable;

final readonly class EventWorker
{
    public function __construct(
        private Consumer $consumer,
        private Serializer $serializer,
        private EventMessageExecutor $executor,
        private EventValidator $events,
        private ErrorMessageSanitizer $errors,
        private ListenerRegistry $listeners,
    ) {
    }

    /** @param callable(): bool $shouldStop */
    public function run(callable $shouldStop): void
    {
        while (!$shouldStop()) {
            $this->runOnce();
        }
    }

    public function runOnce(): int
    {
        $processed = 0;
        foreach ($this->consumer->receive() as $message) {
            $this->process($message);
            ++$processed;
        }

        return $processed;
    }

    private function process(ReceivedMessage $message): void
    {
        if ($message->body === '') {
            $this->fail(
                $message,
                null,
                null,
                new EventConsumeException('消息缺少 message 字段'),
            );

            return;
        }

        try {
            $eventMessage = $this->serializer->deserialize($message->body);
        } catch (Throwable $throwable) {
            $this->fail($message, null, null, $throwable);

            return;
        }

        try {
            $this->listeners->assertQueuedListenersRegistered(
                $eventMessage->event,
                $eventMessage->listeners,
            );
        } catch (Throwable $throwable) {
            $this->fail($message, $eventMessage, null, $throwable);

            return;
        }

        $result = $this->executor->run($eventMessage);
        if (!$result->succeeded()) {
            $this->fail($message, $eventMessage, $result);

            return;
        }

        $this->consumer->acknowledge($message);
    }

    private function fail(
        ReceivedMessage $message,
        ?EventMessage $eventMessage,
        ?EventResult $result,
        ?Throwable $throwable = null,
    ): void {
        $this->consumer->fail(
            $message,
            new Failure(
                messageId: $message->id,
                eventId: $eventMessage?->eventId,
                eventClass: $eventMessage === null ? null : $eventMessage->event::class,
                eventVersion: $eventMessage === null
                    ? null
                    : $this->events->versionOf($eventMessage->event::class),
                traceId: $eventMessage?->traceId,
                messageCreatedAt: $eventMessage?->createdAt,
                listeners: $result?->listeners ?? [],
                errorClass: $throwable === null ? null : $throwable::class,
                errorMessage: $throwable === null
                    ? null
                    : $this->errors->sanitize($throwable->getMessage()),
                failedAt: new DateTimeImmutable(),
            ),
        );
    }
}
