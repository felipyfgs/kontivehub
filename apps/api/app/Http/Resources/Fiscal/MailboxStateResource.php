<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Monitoring\MailboxStateData;
use App\Enums\MailboxDteStatus;
use App\Enums\MailboxMessagesConsultStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MailboxStateData */
final class MailboxStateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MailboxStateData $data */
        $data = $this->resource;
        $state = $data->state;

        $payload = $state === null ? [
            'tenant_id' => $data->tenantId,
            'client_id' => $data->clientId,
            'dte' => [
                'status' => MailboxDteStatus::Unknown->value,
                'source' => null,
                'observed_at' => null,
            ],
            'messages' => [
                'status' => MailboxMessagesConsultStatus::Unknown->value,
                'source' => null,
                'observed_at' => null,
                'official_unread_count' => null,
                'stored_message_count' => 0,
            ],
        ] : [
            'id' => $state->id,
            'tenant_id' => $state->tenant_id,
            'client_id' => $state->client_id,
            'dte' => [
                'status' => $state->dte_status?->value
                    ?? MailboxDteStatus::Unknown->value,
                'source' => $state->dte_source?->value,
                'observed_at' => $state->dte_observed_at
                    ?->toIso8601String(),
            ],
            'messages' => [
                'status' => $state->messages_status?->value
                    ?? MailboxMessagesConsultStatus::Unknown->value,
                'source' => $state->messages_source?->value,
                'observed_at' => $state->messages_observed_at
                    ?->toIso8601String(),
                'official_unread_count' => $state->official_unread_count,
                'stored_message_count' => $state->stored_message_count,
            ],
            'new_messages_indicator' => [
                'value' => $state->new_messages_indicator,
                'semantic' => 'UNOPENED_ONLY',
                'observed_at' => $state->new_messages_indicator_observed_at
                    ?->toIso8601String(),
                'reconciles_mailbox' => false,
            ],
            'updated_at' => $state->updated_at?->toIso8601String(),
        ];

        return $payload + [
            'monitoring' => $this->monitoring($data),
        ];
    }

    /** @return array<string, mixed> */
    private function monitoring(MailboxStateData $data): array
    {
        $state = $data->syncState;

        return [
            'status' => match (true) {
                $state === null || $state->bootstrap_completed_at === null => 'NEVER_SYNCED',
                $state->last_error_code !== null => 'FAILED',
                $state->authorization_status === 'DENIED' => 'BLOCKED',
                $state->pending_event_date !== null => 'PENDING_RECONCILIATION',
                default => 'HEALTHY',
            },
            'bootstrap_completed_at' => $state?->bootstrap_completed_at
                ?->toIso8601String(),
            'last_event_observed_date' => $state?->last_event_observed_date
                ?->toDateString(),
            'pending_event_date' => $state?->pending_event_date?->toDateString(),
            'last_reconciled_event_date' => $state
                ?->last_reconciled_event_date
                ?->toDateString(),
            'last_paid_check_at' => $state?->last_list_at?->toIso8601String(),
            'last_full_reconciliation_at' => $state
                ?->last_full_reconciliation_at
                ?->toIso8601String(),
            'authorization_status' => $state?->authorization_status
                ?? 'UNKNOWN',
            'block_code' => $state?->last_error_code,
        ];
    }
}
