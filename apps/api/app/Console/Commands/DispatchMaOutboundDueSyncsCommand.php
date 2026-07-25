<?php

namespace App\Console\Commands;

use App\Enums\OutboundProfileStatus;
use App\Enums\OutboundSeriesStatus;
use App\Jobs\QueryOutboundSequenceJob;
use App\Models\OutboundSeriesCursor;
use App\Services\Outbound\OutboundKillSwitchService;
use Illuminate\Console\Command;

/**
 * Scheduler: spread determinístico de séries elegíveis para consulta read-only.
 */
class DispatchMaOutboundDueSyncsCommand extends Command
{
    protected $signature = 'sefaz:dispatch-ma-outbound-due';

    protected $description = 'Enfileira reconciliação de saídas MA (nNF) elegíveis';

    public function handle(OutboundKillSwitchService $killSwitch): int
    {
        if (! (bool) config('sefaz.ma_outbound.enabled', false)) {
            return self::SUCCESS;
        }
        if (! (bool) config('sefaz.ma_outbound.protocol_query_enabled', false)) {
            return self::SUCCESS;
        }
        // Config env + kill switch runtime (cache/API)
        if ($killSwitch->isGlobalActive()) {
            return self::SUCCESS;
        }

        $due = OutboundSeriesCursor::query()
            ->whereIn('status', [
                OutboundSeriesStatus::Idle->value,
                OutboundSeriesStatus::SeedReady->value,
            ])
            ->where(function ($q): void {
                $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now());
            })
            ->whereHas('profile', function ($q): void {
                $q->where('status', OutboundProfileStatus::Active->value)
                    ->where('allowlisted', true)
                    ->where('kill_switch', false);
            })
            ->orderBy('id')
            ->limit(50)
            ->get();

        $minute = (int) now()->format('i');
        $dispatched = 0;

        foreach ($due as $series) {
            // Spread determinístico no horário (id % 60)
            if (((int) $series->id % 60) !== $minute && $series->next_run_at !== null) {
                // permite se next_run_at já venceu há mais de 1h
                if ($series->next_run_at->gt(now()->subHour())) {
                    continue;
                }
            }

            QueryOutboundSequenceJob::dispatch($series->id, 'scheduler');
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} MA outbound series jobs.");

        return self::SUCCESS;
    }
}
