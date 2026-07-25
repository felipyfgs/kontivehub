<?php

namespace App\Jobs\Fiscal;

use App\Models\PgdasdRbt12Projection;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdMonitoringQueryService;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdRbt12Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Dispara consulta documental RBT12 (CONSEXTRATO16 ou CONSDECREC15) para reserva PENDING. */
final class FetchPgdasdRbt12Job implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $rbt12ProjectionId) {}

    public function handle(PgdasdRbt12Service $rbt12, PgdasdMonitoringQueryService $queries): void
    {
        $projection = PgdasdRbt12Projection::query()
            ->withoutGlobalScopes()
            ->with(['projection', 'client'])
            ->find($this->rbt12ProjectionId);
        if ($projection === null || $projection->status?->value !== 'PENDING') {
            return;
        }

        try {
            $queries->enqueueAutomaticRbt12Extract($projection->refresh());
            // attempted_at é telemetria, não trava de reentrega. A run 16 já
            // foi persistida com correlação determinística antes desta marca.
            $rbt12->markAttempted($projection->refresh());
        } catch (Throwable) {
            $rbt12->markFailed($projection->refresh(), 'EXTRACT_QUERY_ENQUEUE_FAILED');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $projection = PgdasdRbt12Projection::query()
            ->withoutGlobalScopes()
            ->find($this->rbt12ProjectionId);
        if ($projection !== null && $projection->status?->value === 'PENDING') {
            app(PgdasdRbt12Service::class)->markFailed(
                $projection,
                'EXTRACT_JOB_FAILED',
                $projection->source_run_id,
            );
        }
    }
}
