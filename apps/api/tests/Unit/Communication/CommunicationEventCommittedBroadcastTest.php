<?php

namespace Tests\Unit\Communication;

use App\Events\CommunicationEventCommitted;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Tests\TestCase;

class CommunicationEventCommittedBroadcastTest extends TestCase
{
    public function test_ledger_broadcast_is_immediate_after_commit(): void
    {
        $this->assertTrue(is_subclass_of(CommunicationEventCommitted::class, ShouldBroadcastNow::class));

        $event = new CommunicationEventCommitted(
            officeId: 1,
            cursor: 42,
            eventType: 'MESSAGE_QUEUED',
            inboxId: 7,
            conversationId: 9,
            messageId: 11,
            payload: ['source' => 'test'],
            occurredAt: now()->toIso8601String(),
        );

        $this->assertTrue($event->afterCommit);
        $this->assertSame('communication.event', $event->broadcastAs());
        $this->assertSame([
            'cursor' => 42,
            'type' => 'MESSAGE_QUEUED',
            'inbox_id' => 7,
            'conversation_id' => 9,
            'message_id' => 11,
            'payload' => ['source' => 'test'],
            'occurred_at' => $event->occurredAt,
        ], $event->broadcastWith());
    }
}
