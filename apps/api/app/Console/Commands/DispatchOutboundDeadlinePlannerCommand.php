<?php

namespace App\Console\Commands;

use App\Contracts\SvrsPortalEgressGovernor;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\SvrsNfceRecoveryStatus;
use App\Jobs\PlanOutboundDeadlineScheduleJob;
use App\Jobs\RecoverSvrsNfceXmlJob;
use App\Models\MaOutboundRetrievalRequest;
use App\Services\Outbound\OutboundDeadlineFairQueue;
use App\Services\Outbound\OutboundDeadlineSatisfactionService;
use Illuminate\Console\Command;

class DispatchOutboundDeadlinePlannerCommand extends Command
{
    protected $signature = 'outbound:deadline-plan {--office= : office_id opcional} {--dispatch : enfileira slots vencidos (se flags on)}';

    protected $description = 'Planeja prazos/capacidade de captura de saídas (shadow por default)';

    public function handle(): int
    {
        if (! config('outbound_deadline.enabled') && ! config('outbound_deadline.planner_enabled')) {
            $this->line('OUTBOUND_DEADLINE_* off — nada a planejar.');

            return self::SUCCESS;
        }

        $office = $this->option('office') !== null ? (int) $this->option('office') : null;
        PlanOutboundDeadlineScheduleJob::dispatchSync($office);

        $this->info('Planner de prazo executado.');

        if (! $this->option('dispatch')) {
            return self::SUCCESS;
        }

        if (! config('outbound_deadline.dispatch_enabled') || config('outbound_deadline.shadow_mode')) {
            $this->warn('Dispatch desligado ou shadow_mode ativo — nenhum job remoto enfileirado.');

            return self::SUCCESS;
        }

        $limit = (int) config('outbound_deadline.dispatch_batch_size', 20);
        try {
            $governor = app(SvrsPortalEgressGovernor::class);
            $health = $governor->cohortHealth();
            if (! $governor->isCallAllowed(false) || ($health['state'] ?? 'open') !== 'closed') {
                $this->warn('Governador SVRS sem capacidade automática — preserve o backlog e use fallback assistido.');

                return self::SUCCESS;
            }
            $fraction = min(1.0, max(0.0, (float) config('outbound_deadline.auto_queue_capacity_fraction', 0.60)));
            $remaining = min(
                (int) ($health['exchanges_hour_remaining'] ?? 0),
                (int) ($health['exchanges_day_remaining'] ?? 0),
            );
            $perTransaction = max(1, (int) config('outbound_deadline.exchanges_per_transaction', 2));
            $limit = min($limit, (int) floor(($remaining * $fraction) / $perTransaction));
        } catch (\Throwable) {
            $this->warn('Governador SVRS indisponível — dispatch fail-closed; backlog preservado.');

            return self::SUCCESS;
        }

        if ($limit < 1) {
            $this->warn('Budget preventivo sem slots para auto-queue; priorize autXML, XML/ZIP ou pacote oficial.');

            return self::SUCCESS;
        }

        $dueQuery = MaOutboundRetrievalRequest::query()
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereIn('recovery_status', [
                SvrsNfceRecoveryStatus::Eligible->value,
                SvrsNfceRecoveryStatus::RetryScheduled->value,
                SvrsNfceRecoveryStatus::Queued->value,
            ])
            ->where(function ($q): void {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->where(function ($q): void {
                $q->whereNull('accommodation_until')->orWhere('accommodation_until', '<=', now());
            })
            // Overflow/capacidade em massa fica visível para fallback, sem rajada no portal.
            ->where('capacity_at_risk', false)
            ->limit(max($limit, $limit * 10));

        // Mesmo filtro de office do planner: --office=N não pode despachar slots de outros escritórios.
        if ($office !== null) {
            $dueQuery->where('office_id', $office);
        }

        $candidates = $dueQuery->get();
        $fairQueue = app(OutboundDeadlineFairQueue::class);
        $due = $fairQueue->fairSelect($fairQueue->order($candidates), $limit);

        $n = 0;
        $satisfaction = app(OutboundDeadlineSatisfactionService::class);
        foreach ($due as $req) {
            // Revalidar: se vault/catálogo já tem full, cancela em vez de enfileirar
            if ($req->access_key) {
                $pref = $satisfaction->preferExistingSource((int) $req->office_id, (string) $req->access_key);
                if ($pref['has_full']) {
                    $satisfaction->markCapturedBySource(
                        (int) $req->office_id,
                        (string) $req->access_key,
                        $pref['source'] ?? 'VAULT',
                        $pref['sha256'],
                        $pref['dfe_document_id'],
                    );

                    continue;
                }
            }

            $maxTx = (int) config('outbound_deadline.max_svrs_transactions_per_key', 2);
            if ((int) $req->svrs_transaction_count >= $maxTx) {
                continue;
            }

            RecoverSvrsNfceXmlJob::dispatch($req->id)
                ->onQueue((string) config('outbound_deadline.queue', 'capture-outbound-ma'));
            $n++;
        }

        $this->info("Dispatch: {$n} recovery job(s) enfileirado(s).");

        return self::SUCCESS;
    }
}
