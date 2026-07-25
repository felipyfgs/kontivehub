<?php

namespace App\Jobs;

use App\Enums\OutboundCaptureRunStatus;
use App\Models\OutboundCaptureRun;
use App\Models\OutboundSeriesCursor;
use App\Services\Outbound\OutboundKillSwitchService;
use App\Services\Outbound\OutboundSequenceReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job de reconciliação read-only por série (fila capture-outbound-ma).
 */
class QueryOutboundSequenceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $seriesCursorId,
        public readonly string $triggeredBy = 'scheduler',
        public readonly ?int $userId = null,
    ) {
        $this->onQueue((string) config('sefaz.ma_outbound.queue', 'capture-outbound-ma'));
        $this->timeout = (int) config('sefaz.ma_outbound.job_timeout_seconds', 900);
    }

    public function handle(
        OutboundSequenceReconciler $reconciler,
        OutboundKillSwitchService $killSwitch,
    ): void {
        if (! (bool) config('sefaz.ma_outbound.enabled', false)) {
            return;
        }
        if (! (bool) config('sefaz.ma_outbound.protocol_query_enabled', false)) {
            return;
        }
        if ($killSwitch->isGlobalActive()) {
            return;
        }

        $series = OutboundSeriesCursor::query()->with('profile')->find($this->seriesCursorId);
        if ($series === null) {
            return;
        }
        if ($killSwitch->isBlocked($series->profile)) {
            return;
        }

        $run = OutboundCaptureRun::query()->create([
            'office_id' => $series->office_id,
            'outbound_capture_profile_id' => $series->outbound_capture_profile_id,
            'outbound_series_cursor_id' => $series->id,
            'run_type' => 'SEQUENCE_QUERY',
            'status' => OutboundCaptureRunStatus::Running,
            'started_at' => now(),
            'triggered_by' => $this->triggeredBy,
            'user_id' => $this->userId,
        ]);

        try {
            $result = $reconciler->reconcileSeries($series);
            $run->forceFill([
                'status' => $result['blocked']
                    ? OutboundCaptureRunStatus::Blocked
                    : OutboundCaptureRunStatus::Completed,
                'nnf_start' => $result['nnf_start'],
                'nnf_end' => $result['nnf_end'],
                'numbers_consulted' => $result['consulted'],
                'keys_discovered' => $result['discovered'],
                'gaps_open' => $result['gaps'],
                'attempts_total' => $result['consulted'],
                'result_summary' => sprintf(
                    'consulted=%d discovered=%d gaps=%d',
                    $result['consulted'],
                    $result['discovered'],
                    $result['gaps']
                ),
                'finished_at' => now(),
                'metrics' => [
                    'position_kind' => 'nNF',
                    'blocked' => $result['blocked'],
                ],
            ])->save();

            Log::info('outbound.sequence.job', [
                'series_id' => $series->id,
                'consulted' => $result['consulted'],
                'discovered' => $result['discovered'],
                'blocked' => $result['blocked'],
            ]);
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => OutboundCaptureRunStatus::Failed,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ])->save();
            throw $e;
        }
    }
}
