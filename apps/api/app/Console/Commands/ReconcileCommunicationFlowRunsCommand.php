<?php

namespace App\Console\Commands;

use App\Enums\Communication\FlowRunStatus;
use App\Jobs\Communication\AdvanceFlowRunJob;
use App\Models\CommunicationFlowRun;
use App\Services\Communication\Flows\FlowAvailability;
use App\Services\Communication\Flows\FlowRunControlService;
use Illuminate\Console\Command;

final class ReconcileCommunicationFlowRunsCommand extends Command
{
    protected $signature = 'communication:reconcile-flow-runs {--limit=200}';

    protected $description = 'Retoma delays/timeouts de runs de fluxo de comunicação de forma idempotente.';

    public function handle(
        FlowAvailability $availability,
        FlowRunControlService $controls,
    ): int {
        if (! $availability->runtimeEnabled()) {
            return self::SUCCESS;
        }

        $limit = min(1000, max(1, (int) $this->option('limit')));
        $now = now();

        $delayIds = CommunicationFlowRun::query()->withoutGlobalScopes()
            ->where('status', FlowRunStatus::WaitingDelay->value)
            ->where(function ($q) use ($now): void {
                $q->whereNull('waiting_until')->orWhere('waiting_until', '<=', $now);
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($delayIds as $id) {
            AdvanceFlowRunJob::dispatch((int) $id);
        }

        $outboxIds = CommunicationFlowRun::query()->withoutGlobalScopes()
            ->where('status', FlowRunStatus::WaitingOutbox->value)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($outboxIds as $id) {
            AdvanceFlowRunJob::dispatch((int) $id);
        }

        $timedOut = CommunicationFlowRun::query()->withoutGlobalScopes()
            ->where('status', FlowRunStatus::WaitingInput->value)
            ->whereNotNull('waiting_until')
            ->where('waiting_until', '<=', $now)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($timedOut as $run) {
            // Timeout sem edge dedicada: handoff seguro.
            $controls->handoff($run, null);
        }

        return self::SUCCESS;
    }
}
