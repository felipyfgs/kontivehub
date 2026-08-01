<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\AutXmlCursorData;
use App\DTO\Tenant\AutXmlCursorOverviewData;
use App\Enums\CaptureChannel;
use App\Enums\SyncCursorStatus;
use App\Models\TenantDistributionCursor;
use App\Models\TenantDistributionRun;
use App\Services\Sefaz\AutXmlCircuitBreaker;
use App\Services\Sefaz\TenantAutXmlEnrollmentService;
use App\Support\CurrentTenant;

final readonly class ShowAutXmlCursorAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AutXmlCircuitBreaker $circuitBreaker,
        private TenantAutXmlEnrollmentService $enrollments,
    ) {}

    public function __invoke(): AutXmlCursorOverviewData
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $cursors = TenantDistributionCursor::query()
            ->where('tenant_id', $tenantId)
            ->where('channel', CaptureChannel::NfeAutXmlDistDfe)
            ->orderBy('id')
            ->get();
        $primary = $cursors->first();

        $presentedCursors = $cursors->map(
            function (TenantDistributionCursor $cursor): AutXmlCursorData {
                $breakerOpen = $this->circuitBreaker->isOpen($cursor);
                $persistedOpen = $cursor->last_cstat === '656'
                    || $cursor->status === SyncCursorStatus::Blocked;

                return new AutXmlCursorData(
                    cursor: $cursor,
                    backoff: $cursor->next_sync_at?->isFuture() ?? false,
                    circuitBreakerOpen: $breakerOpen,
                    circuitOpen: $breakerOpen || $persistedOpen,
                );
            },
        );

        $recentRuns = $primary === null
            ? collect()
            : TenantDistributionRun::query()
                ->where('tenant_id', $tenantId)
                ->where('tenant_distribution_cursor_id', $primary->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get();

        return new AutXmlCursorOverviewData(
            cursors: $presentedCursors,
            stream: $this->enrollments->streamGate($primary),
            recentRuns: $recentRuns,
        );
    }
}
