<?php

namespace App\Console\Commands;

use App\Enums\SyncCursorStatus;
use App\Models\SyncCursor;
use App\Models\SyncRun;
use App\Services\Adn\SyncDispatchService;
use App\Services\Clients\CaptureEligibilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DispatchDueSyncsCommand extends Command
{
    protected $signature = 'adn:dispatch-due-syncs';

    protected $description = 'Enfileira sincronizações de cursores vencidos com espalhamento determinístico';

    public function handle(SyncDispatchService $dispatcher, CaptureEligibilityService $eligibility): int
    {
        $now = now();
        $minute = (int) $now->format('i');
        $recovered = $this->recoverExpiredLeases($now);

        $query = SyncCursor::query()
            ->with(['establishment.client'])
            ->whereNotIn('status', [SyncCursorStatus::Blocked->value, SyncCursorStatus::Running->value])
            ->whereNull('locked_at')
            ->whereNull('lock_owner')
            ->where(function ($q) use ($now): void {
                $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', $now);
            })
            ->orderBy('id');

        $dispatched = 0;
        $skippedIneligible = 0;
        $query->chunkById(100, function ($cursors) use ($dispatcher, $eligibility, $minute, &$dispatched, &$skippedIneligible): void {
            foreach ($cursors as $cursor) {
                // Primeiro ciclo (next_sync_at nulo): espalha por id % 60.
                // Ciclos seguintes: next_sync_at já aponta ao minuto preferencial (job).
                // WAITING/ERROR com next_sync_at vencido: dispara sem esperar o minuto.
                $preferred = $cursor->id % 60;
                if ($cursor->next_sync_at === null && $preferred !== $minute) {
                    continue;
                }

                $establishment = $cursor->establishment;
                if ($establishment === null || ! $eligibility->isEligible($establishment, $cursor)) {
                    // Não enfileira; NSU permanece intacto.
                    $skippedIneligible++;

                    continue;
                }

                if ($dispatcher->claimAndDispatch($cursor->id)) {
                    $dispatched++;
                }
            }
        });

        $this->info("Leases recuperados: {$recovered}; disparados: {$dispatched}; inelegíveis: {$skippedIneligible}");

        return self::SUCCESS;
    }

    private function recoverExpiredLeases(Carbon $now): int
    {
        $minimumStaleSeconds = max(
            (int) config('adn.job_timeout_seconds', 900),
            (int) config('adn.lock_ttl_seconds', 960),
        ) + 60;
        $staleSeconds = max(
            $minimumStaleSeconds,
            (int) config('adn.stale_lease_seconds', 1020),
        );
        $cutoff = $now->copy()->subSeconds($staleSeconds);
        $recovered = 0;

        SyncCursor::query()
            ->whereIn('status', [SyncCursorStatus::Running, SyncCursorStatus::Waiting])
            ->whereNotNull('locked_at')
            ->where('locked_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($cursors) use ($cutoff, $now, &$recovered): void {
                foreach ($cursors as $cursor) {
                    $lock = Cache::lock('adn:est:'.$cursor->establishment_id, 30);
                    if (! $lock->get()) {
                        continue;
                    }

                    try {
                        $wasRecovered = DB::transaction(function () use ($cursor, $cutoff, $now): bool {
                            $fresh = SyncCursor::query()->whereKey($cursor->id)->lockForUpdate()->first();

                            if ($fresh === null
                                || ! in_array($fresh->status, [SyncCursorStatus::Running, SyncCursorStatus::Waiting], true)
                                || $fresh->locked_at === null
                                || $fresh->locked_at->greaterThan($cutoff)) {
                                return false;
                            }

                            $message = 'Lease de sincronização expirou; execução reagendada.';
                            $fresh->status = SyncCursorStatus::Error;
                            $fresh->attempts++;
                            $fresh->next_sync_at = $now;
                            $fresh->locked_at = null;
                            $fresh->lock_owner = null;
                            $fresh->last_error = $message;
                            $fresh->save();

                            SyncRun::query()
                                ->where('sync_cursor_id', $fresh->id)
                                ->where('status', 'RUNNING')
                                ->lockForUpdate()
                                ->get()
                                ->each(function (SyncRun $run) use ($message, $now): void {
                                    $run->status = 'FAILED';
                                    $run->error_message = $message;
                                    $run->finished_at = $now;
                                    $run->save();
                                });

                            return true;
                        });

                        if ($wasRecovered) {
                            $recovered++;
                        }
                    } finally {
                        $lock->release();
                    }
                }
            });

        return $recovered;
    }
}
