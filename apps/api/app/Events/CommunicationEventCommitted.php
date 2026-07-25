<?php

namespace App\Events;

use App\Models\CommunicationEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CommunicationEventCommitted implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public bool $afterCommit = true;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $officeId,
        public readonly int $cursor,
        public readonly string $eventType,
        public readonly ?int $inboxId,
        public readonly ?int $conversationId,
        public readonly ?int $messageId,
        public readonly array $payload,
        public readonly string $occurredAt,
    ) {}

    public static function fromModel(CommunicationEvent $event): self
    {
        return new self(
            officeId: (int) $event->office_id,
            cursor: (int) $event->id,
            eventType: $event->type,
            inboxId: $event->inbox_id !== null ? (int) $event->inbox_id : null,
            conversationId: $event->conversation_id !== null ? (int) $event->conversation_id : null,
            messageId: $event->message_id !== null ? (int) $event->message_id : null,
            payload: $event->payload ?? [],
            occurredAt: $event->occurred_at->toIso8601String(),
        );
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->inboxId !== null
            ? 'communication.inbox.'.$this->inboxId
            : 'communication.office.'.$this->officeId)];
    }

    public function broadcastAs(): string
    {
        return 'communication.event';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'cursor' => $this->cursor,
            'type' => $this->eventType,
            'inbox_id' => $this->inboxId,
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'payload' => $this->payload,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
