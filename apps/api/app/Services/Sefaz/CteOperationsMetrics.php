<?php

namespace App\Services\Sefaz;

use App\Enums\CaptureChannel;
use App\Enums\DocumentAcquisitionSource;
use App\Models\ChannelSyncCursor;
use App\Models\CteCoverageSnapshot;
use App\Models\DocumentAcquisition;
use App\Models\FiscalDocumentQuarantine;
use App\Models\OfficeDistributionCursor;
use App\Models\OfficeDistributionRun;

/** Snapshot CT-e de baixa cardinalidade, sempre escopado ao escritório. */
final class CteOperationsMetrics
{
    /** @return array<string, mixed> */
    public function snapshot(int $officeId, ?string $period = null): array
    {
        $period ??= now()->format('Y-m');
        $client = ChannelSyncCursor::query()
            ->where('office_id', $officeId)
            ->where('channel', CaptureChannel::CteDistDfe->value);
        $office = OfficeDistributionCursor::query()
            ->where('office_id', $officeId)
            ->where('channel', CaptureChannel::CteAutXmlDistDfe->value);
        $runs = OfficeDistributionRun::query()
            ->where('office_id', $officeId)
            ->where('created_at', '>=', now()->subDay());
        $acquisitions = DocumentAcquisition::query()
            ->where('office_id', $officeId)
            ->whereIn('source', array_map(fn (DocumentAcquisitionSource $source) => $source->value, [
                DocumentAcquisitionSource::CteDistNsu,
                DocumentAcquisitionSource::CteAutXmlDistNsu,
                DocumentAcquisitionSource::CteDistDfe,
                DocumentAcquisitionSource::EmitterPush,
                DocumentAcquisitionSource::ManualXml,
                DocumentAcquisitionSource::ManualZip,
            ]));

        return [
            'period' => $period,
            'channels' => [
                CaptureChannel::CteDistDfe->value => $this->cursorMetrics(clone $client),
                CaptureChannel::CteAutXmlDistDfe->value => $this->cursorMetrics(clone $office),
            ],
            'runs_24h' => [
                'total' => (clone $runs)->count(),
                'failed' => (clone $runs)->where('status', 'FAILED')->count(),
                'pages' => (int) (clone $runs)->sum('pages_processed'),
                'documents' => (int) (clone $runs)->sum('documents_persisted'),
                'quarantined' => (int) (clone $runs)->sum('documents_quarantined'),
            ],
            'quarantine_open' => FiscalDocumentQuarantine::query()
                ->where('office_id', $officeId)
                ->where('resolution_status', 'OPEN')
                ->where(fn ($query) => $query->where('model', '57')->orWhere('schema_family', 'like', '%CTe%'))
                ->count(),
            'quality' => (clone $acquisitions)
                ->whereNotNull('artifact_quality')
                ->selectRaw('artifact_quality, count(*) as aggregate')
                ->groupBy('artifact_quality')
                ->pluck('aggregate', 'artifact_quality')
                ->map(fn ($value) => (int) $value)
                ->all(),
            'coverage' => CteCoverageSnapshot::query()
                ->where('office_id', $officeId)
                ->where('period', $period)
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->map(fn ($value) => (int) $value)
                ->all(),
        ];
    }

    /** @param \Illuminate\Database\Eloquent\Builder<*> $query */
    private function cursorMetrics($query): array
    {
        $now = now();
        $lastSuccess = (clone $query)->max('last_success_at');

        return [
            'streams' => (clone $query)->count(),
            'blocked' => (clone $query)->where('status', 'BLOCKED')->count(),
            'error' => (clone $query)->where('status', 'ERROR')->count(),
            'due' => (clone $query)->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', $now))->count(),
            'decode_failures' => (int) (clone $query)->sum('consecutive_decode_failures'),
            'last_success_age_seconds' => $lastSuccess ? max(0, $now->diffInSeconds($lastSuccess)) : null,
            'cstat' => (clone $query)
                ->whereNotNull('last_cstat')
                ->selectRaw('last_cstat, count(*) as aggregate')
                ->groupBy('last_cstat')
                ->pluck('aggregate', 'last_cstat')
                ->map(fn ($value) => (int) $value)
                ->all(),
        ];
    }
}
