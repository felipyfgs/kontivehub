<?php

namespace App\Services\Communication\Events;

use App\DTO\Communication\CommunicationPayloadDigest;
use App\Events\CommunicationEventCommitted;
use App\Models\CommunicationEvent;
use Illuminate\Support\Facades\DB;

final class CommunicationEventRecorder
{
    /** @param array<string, mixed> $payload */
    public function record(
        int $officeId,
        string $type,
        array $payload = [],
        ?int $inboxId = null,
        ?int $conversationId = null,
        ?int $messageId = null,
        ?int $actorMembershipId = null,
        ?string $gatewayEventId = null,
        ?string $payloadDigest = null,
        mixed $occurredAt = null,
    ): CommunicationEvent {
        $event = CommunicationEvent::query()->withoutGlobalScopes()->create([
            'office_id' => $officeId,
            'inbox_id' => $inboxId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'actor_membership_id' => $actorMembershipId,
            'type' => $type,
            'gateway_event_id' => $gatewayEventId,
            'payload_digest' => $payloadDigest ?? CommunicationPayloadDigest::make($payload),
            'payload' => $payload,
            'occurred_at' => $occurredAt ?? now(),
        ]);

        DB::afterCommit(static fn () => event(CommunicationEventCommitted::fromModel($event)));

        return $event;
    }
}
