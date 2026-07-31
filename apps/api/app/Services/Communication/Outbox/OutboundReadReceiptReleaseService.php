<?php

namespace App\Services\Communication\Outbox;

use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\OutboxStatus;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Services\Communication\Events\EventRecorder;

final readonly class OutboundReadReceiptReleaseService
{
    public function __construct(
        private OutboxService $outbox,
        private EventRecorder $events,
    ) {}

    public function release(CommunicationOutboxEntry $accepted): ?CommunicationOutboxEntry
    {
        $accepted->loadMissing([
            'inbox' => fn ($query) => $query->withoutGlobalScopes(),
            'message' => fn ($query) => $query->withoutGlobalScopes(),
        ]);
        if ($accepted->status !== OutboxStatus::Accepted
            || $accepted->type !== GatewayCommandType::SendMessage
            || $accepted->inbox === null
            || $accepted->message === null) {
            return null;
        }

        $metadata = is_array($accepted->message->metadata) ? $accepted->message->metadata : [];
        $receiptMessageId = (int) ($metadata['receipt_message_id'] ?? 0);
        if ($receiptMessageId < 1) {
            return null;
        }
        $conversation = CommunicationConversation::query()
            ->withoutGlobalScopes()
            ->with(['identity' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('tenant_id', $accepted->tenant_id)
            ->where('inbox_id', $accepted->inbox_id)
            ->whereKey($accepted->message->conversation_id)
            ->lockForUpdate()
            ->first();
        if ($conversation === null || $conversation->purged_at !== null) {
            return $this->failed($accepted, $receiptMessageId, 'CONVERSATION_UNAVAILABLE');
        }
        $target = CommunicationMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $accepted->tenant_id)
            ->where('inbox_id', $accepted->inbox_id)
            ->where('conversation_id', $accepted->message->conversation_id)
            ->where('direction', MessageDirection::Inbound)
            ->whereNull('purged_at')
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->find($receiptMessageId);
        if ($target === null || trim((string) $target->provider_message_id) === '') {
            return $this->failed($accepted, $receiptMessageId, 'TARGET_UNAVAILABLE');
        }
        $address = trim((string) $conversation?->identity?->address_encrypted);
        if ($conversation->identity?->purged_at !== null || $address === '') {
            return $this->failed($accepted, $receiptMessageId, 'IDENTITY_UNAVAILABLE');
        }

        return $this->outbox->enqueueAcceptedFollowUp(
            inbox: $accepted->inbox,
            type: GatewayCommandType::MarkMessage,
            payload: [
                'to' => $address,
                'message_ids' => [(string) $target->provider_message_id],
                'receipt' => 'READ',
                'sender' => $address,
                'timestamp' => $target->occurred_at?->getTimestamp() ?? now()->getTimestamp(),
                'protocol' => false,
            ],
            message: $accepted->message,
            effectKey: 'outbound-read-receipt:'.$accepted->id.':'.$target->id,
        );
    }

    private function failed(
        CommunicationOutboxEntry $accepted,
        int $receiptMessageId,
        string $code,
    ): null {
        $this->events->record(
            tenantId: (int) $accepted->tenant_id,
            type: 'outbound.read_receipt.release_failed',
            payload: [
                'outbox_entry_id' => (int) $accepted->id,
                'message_id' => (int) $accepted->message_id,
                'receipt_message_id' => $receiptMessageId,
                'code' => $code,
            ],
            inboxId: (int) $accepted->inbox_id,
            conversationId: (int) $accepted->message?->conversation_id,
            messageId: (int) $accepted->message_id,
        );

        return null;
    }
}
