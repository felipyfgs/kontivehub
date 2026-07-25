<?php

namespace App\Jobs\Mailbox;

use App\Jobs\Serpro\PollEventosAtualizacaoJob;
use App\Models\MailboxMonitoringSetting;
use App\Models\Office;
use App\Models\SerproEventosRun;
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

    public function __construct(public readonly int $officeId)
    {
        $this->onQueue((string) config('serpro.eventos.queue', 'fiscal'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('mailbox-monitoring:'.$this->officeId))->expireAfter(900)->releaseAfter(60)];
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
            ->where('office_id', $this->officeId)->where('enabled', true)->first();
        $office = Office::query()->withoutGlobalScopes()->whereKey($this->officeId)->where('is_active', true)->first();
        if ($setting === null || $office === null) {
            return;
        }

        foreach ($contributors->batches($office) as $batch) {
            $run = $events->solicit($office, 'PJ', 'E0601', contributorIdentities: $batch);
            if ($run->status === SerproEventosRun::STATUS_RUNNING) {
                $delay = max(1, (int) ceil(((int) $run->tempo_espera_medio_ms) / 1000));
                PollEventosAtualizacaoJob::dispatch($run->id)->delay(now()->addSeconds($delay));
            }
        }

        // Bootstrap e reconciliação faturável são previstos/bloqueados antes de criar as runs.
        $preview = $sync->preview($office, $setting);
        if ($preview['can_confirm']) {
            $sync->confirm($office, $setting);
        }
        $setting->forceFill(['last_dispatched_at' => now()])->save();
    }
}
