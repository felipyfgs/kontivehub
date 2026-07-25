<?php

namespace App\Services\Communication\Automation;

use App\Enums\Communication\MessageStatus;
use App\Enums\CommunicationDispatchStatus;
use App\Models\ClientCommunicationDispatch;
use App\Models\ClientCommunicationEvent;
use App\Models\CommunicationMessage;
use DateTimeInterface;

final class FiscalDispatchStatusProjector
{
    public function project(
        CommunicationMessage $message,
        MessageStatus $messageStatus,
        DateTimeInterface $occurredAt,
        string $source,
        ?string $providerEventId = null,
        ?string $payloadDigest = null,
    ): void {
        if ($message->client_communication_dispatch_id === null) {
            return;
        }
        $dispatch = ClientCommunicationDispatch::query()->withoutGlobalScopes()
            ->lockForUpdate()->find($message->client_communication_dispatch_id);
        if (! $dispatch instanceof ClientCommunicationDispatch
            || in_array($dispatch->status, [
                CommunicationDispatchStatus::SkippedNoDocument,
                CommunicationDispatchStatus::Canceled,
            ], true)) {
            return;
        }
        $incoming = $this->dispatchStatus($messageStatus);
        if ($incoming === null || ! $this->shouldApply($dispatch->status, $incoming)) {
            return;
        }
        $timestamp = match ($incoming) {
            CommunicationDispatchStatus::Accepted => 'accepted_at',
            CommunicationDispatchStatus::Sent => 'sent_at',
            CommunicationDispatchStatus::Delivered => 'delivered_at',
            CommunicationDispatchStatus::Read => 'read_at',
            CommunicationDispatchStatus::Failed, CommunicationDispatchStatus::Unknown => 'failed_at',
            CommunicationDispatchStatus::Canceled => 'canceled_at',
            default => null,
        };
        $attributes = ['status' => $incoming];
        if ($timestamp !== null) {
            $attributes[$timestamp] = $occurredAt;
        }
        $dispatch->forceFill($attributes)->save();
        ClientCommunicationEvent::query()->withoutGlobalScopes()->create([
            'office_id' => $dispatch->office_id,
            'dispatch_id' => $dispatch->id,
            'status' => $incoming->value,
            'occurred_at' => $occurredAt,
            'received_at' => now(),
            'source' => mb_substr($source, 0, 40),
            'provider_event_id' => $providerEventId,
            'payload_digest' => $payloadDigest,
            'metadata' => ['message_id' => (int) $message->id],
        ]);
    }

    private function dispatchStatus(MessageStatus $status): ?CommunicationDispatchStatus
    {
        return match ($status) {
            MessageStatus::Queued => CommunicationDispatchStatus::Queued,
            MessageStatus::Accepted => CommunicationDispatchStatus::Accepted,
            MessageStatus::Sent => CommunicationDispatchStatus::Sent,
            MessageStatus::Delivered => CommunicationDispatchStatus::Delivered,
            MessageStatus::Read => CommunicationDispatchStatus::Read,
            MessageStatus::Played => CommunicationDispatchStatus::Read,
            MessageStatus::Failed => CommunicationDispatchStatus::Failed,
            MessageStatus::Unknown => CommunicationDispatchStatus::Unknown,
            MessageStatus::Canceled => CommunicationDispatchStatus::Canceled,
        };
    }

    private function shouldApply(
        CommunicationDispatchStatus $current,
        CommunicationDispatchStatus $incoming,
    ): bool {
        if ($current === $incoming) {
            return false;
        }
        $successful = [
            CommunicationDispatchStatus::Scheduled->value => 0,
            CommunicationDispatchStatus::Queued->value => 10,
            CommunicationDispatchStatus::Accepted->value => 20,
            CommunicationDispatchStatus::Sent->value => 30,
            CommunicationDispatchStatus::Delivered->value => 40,
            CommunicationDispatchStatus::Read->value => 50,
        ];
        if (isset($successful[$incoming->value])) {
            if (in_array($current, [CommunicationDispatchStatus::Failed, CommunicationDispatchStatus::Unknown], true)) {
                return $successful[$incoming->value] >= 30;
            }

            return $successful[$incoming->value] > ($successful[$current->value] ?? -1);
        }
        if (in_array($incoming, [CommunicationDispatchStatus::Failed, CommunicationDispatchStatus::Unknown], true)) {
            return ($successful[$current->value] ?? 0) <= 20;
        }

        return $incoming === CommunicationDispatchStatus::Canceled
            && ($successful[$current->value] ?? 0) <= 20;
    }
}
