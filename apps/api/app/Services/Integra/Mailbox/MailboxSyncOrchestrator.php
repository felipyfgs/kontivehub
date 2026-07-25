<?php

namespace App\Services\Integra\Mailbox;

use App\Enums\MailboxMonitoringMode;
use App\Models\Client;
use App\Models\MailboxClientSyncState;
use App\Models\MailboxMonitoringSetting;
use App\Models\Office;

final class MailboxSyncOrchestrator
{
    public function __construct(
        private readonly MailboxContributorBatchBuilder $contributors,
        private readonly MailboxCostPolicy $cost,
        private readonly MailboxListRunDispatcher $lists,
    ) {}

    /** @return array<string,mixed> */
    public function preview(Office $office, ?MailboxMonitoringSetting $setting = null, bool $forceAll = false): array
    {
        $setting ??= MailboxMonitoringSetting::query()->withoutGlobalScopes()
            ->firstOrNew(['office_id' => $office->id]);
        $contributors = $this->contributors->contributors($office);
        $clientIds = array_values(array_unique(array_column($contributors, 'client_id')));
        $states = MailboxClientSyncState::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)->whereIn('client_id', $clientIds)
            ->get()->keyBy('client_id');
        $due = [];
        $reasonByClient = [];
        foreach ($clientIds as $clientId) {
            $state = $states->get($clientId);
            $reason = null;
            if ($forceAll || $setting->mode === MailboxMonitoringMode::DailyComplete) {
                $reason = $forceAll ? 'manual' : 'daily_complete';
            } elseif ($state === null || $state->bootstrap_completed_at === null) {
                $reason = 'bootstrap';
            } elseif ($state->last_full_reconciliation_at === null
                || $state->last_full_reconciliation_at->lte(now()->subDays($setting->reconciliation_days))) {
                $reason = 'periodic_reconciliation';
            }
            if ($reason !== null) {
                $due[] = $clientId;
                $reasonByClient[$clientId] = $reason;
            }
        }
        $cost = $due === []
            ? $this->emptyCost()
            : $this->cost->preview((int) $office->id, 'LISTAR', count($due));

        return [
            'mode' => $setting->mode->value,
            'eligible_clients' => count($clientIds),
            'clients_to_list' => count($due),
            'event_batches' => count(array_chunk(array_column($contributors, 'ni'), 1000)),
            'client_ids' => $due,
            'reasons' => $reasonByClient,
            'cost' => $cost,
            'can_confirm' => $due === [] || $cost['allowed'],
        ];
    }

    /** @return array{preview:array<string,mixed>,runs:array} */
    public function confirm(Office $office, ?MailboxMonitoringSetting $setting = null, bool $forceAll = false): array
    {
        $preview = $this->preview($office, $setting, $forceAll);
        if (! $preview['can_confirm']) {
            throw new \RuntimeException((string) $preview['cost']['block_reason']);
        }
        $clients = Client::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereIn('id', $preview['client_ids'])
            ->where('is_active', true)->orderBy('id')->get()->all();
        $reason = $forceAll ? 'manual' : (($setting?->mode === MailboxMonitoringMode::DailyComplete)
            ? 'daily_complete' : 'periodic_reconciliation');
        $runs = $this->lists->dispatch($office, $clients, $reason, ! $forceAll);

        return ['preview' => $preview, 'runs' => $runs];
    }

    /** @return array<string,mixed> */
    private function emptyCost(): array
    {
        return [
            'operation' => 'LISTAR', 'quantity' => 0, 'estimated_cost_micros' => 0,
            'unit_cost_micros' => null, 'currency' => 'BRL', 'price_source' => 'UNKNOWN',
            'price_revision' => null, 'allowed' => true, 'block_reason' => null,
            'budget_micros' => null, 'spent_micros' => 0,
        ];
    }
}
