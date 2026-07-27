<?php

namespace App\Jobs\Mailbox;

use App\Jobs\Serpro\PollEventosAtualizacaoJob;
use App\Models\MailboxMonitoringSetting;
use App\Models\SerproEventosRun;
use App\Models\Tenant;
use App\Services\Integra\Eventos\EventosAtualizacaoFlowService;
use App\Services\Integra\Mailbox\MailboxContributorBatchBuilder;
use App\Services\Integra\Mailbox\MailboxSyncOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class DispatchMailboxMonitoringJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly int $tenantId)
    {
        $this->onQueue((string) config('serpro.eventos.queue', 'fiscal'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('mailbox-monitoring:'.$this->tenantId))->expireAfter(900)->releaseAfter(60)];
    }

    public function handle(
        MailboxContributorBatchBuilder $contributors,
        EventosAtualizacaoFlowService $events,
        MailboxSyncOrchestrator $sync,
    ): void {
        if (! (bool) config('fiscal_monitoring.mailbox.economic_monitoring.enabled', false)) {
            return;
        }
        $setting = MailboxMonitoringSetting::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)->where('enabled', true)->first();
        $tenant = Tenant::query()->withoutGlobalScopes()->whereKey($this->tenantId)->where('is_active', true)->first();
        if ($setting === null || $tenant === null) {
            return;
        }

        foreach ($contributors->batches($tenant) as $batch) {
            $run = $events->solicit($tenant, 'PJ', 'E0601', contributorIdentities: $batch);
            if ($run->status === SerproEventosRun::STATUS_RUNNING) {
                $delay = max(1, (int) ceil(((int) $run->tempo_espera_medio_ms) / 1000));
                PollEventosAtualizacaoJob::dispatch($run->id)->delay(now()->addSeconds($delay));
            }
        }

        // Bootstrap e reconciliação faturável são previstos/bloqueados antes de criar as runs.
        $preview = $sync->preview($tenant, $setting);
        if ($preview['can_confirm']) {
            $sync->confirm($tenant, $setting);
        }
        $setting->forceFill(['last_dispatched_at' => now()])->save();
    }
}
