<?php

namespace App\Services\Integra\Mailbox;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalIdempotency;
use Illuminate\Support\Str;

final class MailboxListRunDispatcher
{
    public function __construct(private readonly MailboxCostPolicy $cost) {}

    /** @param list<Client> $clients @return list<FiscalMonitoringRun> */
    public function dispatch(Office $office, array $clients, string $reason, bool $fullReconciliation = false): array
    {
        if ($clients === []) {
            return [];
        }
        $this->cost->assertAllowed((int) $office->id, 'LISTAR', count($clients));

        $runs = [];
        foreach ($clients as $client) {
            if ((int) $client->office_id !== (int) $office->id || ! $client->is_active) {
                continue;
            }
            $slot = sprintf('mailbox:%s:%s', strtolower($reason), now($this->timezone())->format('Y-m-d'));
            $key = FiscalIdempotency::runKey(
                (int) $office->id,
                (int) $client->id,
                'INTEGRA_CAIXAPOSTAL',
                'CAIXA_POSTAL',
                'LISTAR',
                null,
                $reason === 'manual' ? FiscalTrigger::Manual : FiscalTrigger::Reconciliation,
                $slot,
            );
            $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->firstOrCreate(
                ['office_id' => $office->id, 'idempotency_key' => $key],
                [
                    'client_id' => $client->id,
                    'system_code' => 'INTEGRA_CAIXAPOSTAL',
                    'service_code' => 'CAIXA_POSTAL',
                    'operation_code' => 'LISTAR',
                    'operation_key' => 'caixa_postal.lista',
                    'trigger' => $reason === 'manual' ? FiscalTrigger::Manual : FiscalTrigger::Reconciliation,
                    'status' => FiscalRunStatus::Queued,
                    'situation' => FiscalSituation::Unknown,
                    'coverage' => FiscalCoverage::Unknown,
                    'mutability' => FiscalMutability::ReadOnly,
                    'correlation_id' => (string) Str::uuid(),
                    'progress' => [
                        'mailbox_reason' => $reason,
                        'mailbox_full_reconciliation' => $fullReconciliation,
                    ],
                ],
            );
            $runs[] = $run;
            if ($run->wasRecentlyCreated) {
                ExecuteFiscalMonitoringRunJob::dispatch($run->id)
                    ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
            }
        }

        return $runs;
    }

    private function timezone(): string
    {
        return (string) config('serpro.eventos.timezone', 'America/Sao_Paulo');
    }
}
