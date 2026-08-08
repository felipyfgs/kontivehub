<?php

namespace App\Services\Communication\Transport;

use App\Contracts\CommunicationCommandPublisher;
use App\Contracts\GatewayEventQueue;
use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayEventDelivery;
use Basis\Nats\Client;
use Basis\Nats\Consumer\AckPolicy;
use Basis\Nats\Consumer\Configuration as ConsumerConfiguration;
use Basis\Nats\Message\Payload;
use Basis\Nats\Queue;
use Basis\Nats\Stream\Configuration as StreamConfiguration;
use Basis\Nats\Stream\DiscardPolicy;
use Basis\Nats\Stream\RetentionPolicy;
use Basis\Nats\Stream\StorageBackend;
use Basis\Nats\Stream\Stream;
use RuntimeException;
use Throwable;

final class JetStreamBroker implements CommunicationCommandPublisher, GatewayEventQueue
{
    private ?Queue $eventQueue = null;

    private bool $streamReady = false;

    public function __construct(private readonly Client $client) {}

    public function publish(GatewayCommandData $command): void
    {
        $this->ensureStream();
        $body = json_encode(
            $command->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        try {
            $this->stream()->publish(
                $this->commandSubject(),
                new Payload($body, ['Nats-Msg-Id' => $command->commandId]),
            );
        } catch (Throwable $error) {
            throw new RuntimeException('Falha ao publicar comando no JetStream.', previous: $error);
        }
    }

    public function next(float $timeoutSeconds = 1.0): ?GatewayEventDelivery
    {
        $queue = $this->eventQueue();
        $queue->setTimeout(max(0.1, min(10.0, $timeoutSeconds)));
        $message = $queue->fetch();
        if ($message === null || $message->payload->isEmpty()) {
            return null;
        }

        return new GatewayEventDelivery(
            body: (string) $message->payload,
            acknowledge: static fn () => $message->ack(),
            retry: static fn (float $delay) => $message->nack(max(0.0, min(300.0, $delay))),
            terminate: static fn () => $message->term('invalid gateway event envelope'),
        );
    }

    private function eventQueue(): Queue
    {
        if ($this->eventQueue !== null) {
            return $this->eventQueue;
        }

        $this->ensureStream();
        $consumer = $this->stream()->getConsumer($this->eventConsumer());
        $exists = $consumer->exists();
        $configuration = $consumer->getConfiguration();
        if ($exists) {
            $this->assertEventConsumerConfiguration($configuration);
        }
        $configuration
            ->setAckPolicy(AckPolicy::EXPLICIT)
            ->setAckWait(120 * 1_000_000_000)
            ->setMaxAckPending(64)
            ->setMaxDeliver(-1)
            ->setSubjectFilter($this->eventSubject());
        $this->client->api(
            'CONSUMER.DURABLE.CREATE.'.config('communication.nats.stream').'.'.$this->eventConsumer(),
            $configuration->toArray(),
        );
        $consumer->setBatching(1)->setExpires(1.0);

        return $this->eventQueue = $consumer->getQueue();
    }

    private function ensureStream(): void
    {
        if ($this->streamReady) {
            return;
        }
        $stream = $this->stream();
        if ($stream->exists()) {
            $this->assertStreamConfiguration($stream->getConfiguration());
            $this->streamReady = true;

            return;
        }
        $stream->getConfiguration()
            ->setSubjects([$this->eventSubject(), $this->commandSubject()])
            ->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
            ->setStorageBackend(StorageBackend::FILE)
            ->setMaxAge(14 * 24 * 60 * 60 * 1_000_000_000)
            ->setDuplicateWindow(24 * 60 * 60)
            ->setMaxMessageSize($this->maxMessageBytes());
        $stream->create();
        $this->streamReady = true;
    }

    private function assertStreamConfiguration(StreamConfiguration $configuration): void
    {
        $actualSubjects = $configuration->getSubjects();
        $desiredSubjects = [$this->eventSubject(), $this->commandSubject()];
        sort($actualSubjects);
        sort($desiredSubjects);
        $drift = [];
        if ($actualSubjects !== $desiredSubjects) {
            $drift[] = 'subjects';
        }
        if ($configuration->getRetentionPolicy() !== RetentionPolicy::WORK_QUEUE) {
            $drift[] = 'retention';
        }
        if ($configuration->getStorageBackend() !== StorageBackend::FILE) {
            $drift[] = 'storage';
        }
        if ($configuration->getDiscardPolicy() !== DiscardPolicy::OLD) {
            $drift[] = 'discard';
        }
        if ($configuration->getMaxAge() !== 14 * 24 * 60 * 60 * 1_000_000_000) {
            $drift[] = 'max_age';
        }
        if ((int) $configuration->getDuplicateWindow() !== 24 * 60 * 60) {
            $drift[] = 'duplicates';
        }
        if ($configuration->getMaxMessageSize() !== $this->maxMessageBytes()) {
            $drift[] = 'max_message_size';
        }
        if ($drift !== []) {
            throw new RuntimeException('Configuração divergente do stream JetStream: '.implode(', ', $drift).'.');
        }
    }

    private function assertEventConsumerConfiguration(ConsumerConfiguration $configuration): void
    {
        if ($configuration->getAckPolicy() !== AckPolicy::EXPLICIT
            || $configuration->getSubjectFilter() !== $this->eventSubject()
            || $configuration->getDeliverSubject() !== null) {
            throw new RuntimeException('Configuração estrutural divergente do consumer JetStream.');
        }
    }

    private function maxMessageBytes(): int
    {
        return max(1, (int) config('communication.nats.max_message_bytes', 1_048_576));
    }

    private function stream(): Stream
    {
        return $this->client->getApi()->getStream((string) config('communication.nats.stream'));
    }

    private function eventSubject(): string
    {
        return (string) config('communication.nats.event_subject');
    }

    private function commandSubject(): string
    {
        return (string) config('communication.nats.command_subject');
    }

    private function eventConsumer(): string
    {
        return (string) config('communication.nats.event_consumer');
    }
}
