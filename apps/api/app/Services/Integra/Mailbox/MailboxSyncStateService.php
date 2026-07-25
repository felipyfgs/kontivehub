<?php

namespace App\Services\Integra\Mailbox;

use App\Models\Client;
use App\Models\MailboxClientSyncState;
use App\Models\Office;

final class MailboxSyncStateService
{
    public function markListSucceeded(Office $office, Client $client, bool $fullReconciliation = false): MailboxClientSyncState
    {
        $state = MailboxClientSyncState::query()->withoutGlobalScopes()->firstOrCreate([
            'office_id' => $office->id,
            'client_id' => $client->id,
        ]);
        $pending = $state->pending_event_date;
        $state->forceFill([
            'bootstrap_completed_at' => $state->bootstrap_completed_at ?? now(),
            'last_list_at' => now(),
            'last_reconciled_event_date' => $pending ?? $state->last_reconciled_event_date,
            'pending_event_date' => null,
            'last_full_reconciliation_at' => $fullReconciliation
                ? now()
                : $state->last_full_reconciliation_at,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        return $state->fresh() ?? $state;
    }

    public function markListFailed(Office $office, Client $client, string $code): MailboxClientSyncState
    {
        $state = MailboxClientSyncState::query()->withoutGlobalScopes()->firstOrCreate([
            'office_id' => $office->id,
            'client_id' => $client->id,
        ]);
        $state->forceFill([
            'last_error_code' => mb_substr($code, 0, 80),
            'last_error_message' => 'A reconciliação LISTAR não foi concluída.',
        ])->save();

        return $state;
    }
}
