<?php

namespace App\Services\Sefaz;

use App\Enums\CteCoverageStatus;
use App\Enums\DocumentArtifactQuality;
use App\Enums\QuarantineResolutionStatus;
use App\Enums\SyncCursorStatus;
use App\Models\ChannelSyncCursor;
use App\Models\Client;
use App\Models\CteCoverageSnapshot;
use App\Models\CteDocument;
use App\Models\DocumentAcquisition;
use App\Models\DocumentInterest;
use App\Models\FiscalDocumentQuarantine;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Projeção conservadora de cobertura CT-e por cliente e competência. */
final class CteCoverageService
{
    public function recompute(int $officeId, int $clientId, string $period): CteCoverageSnapshot
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new InvalidArgumentException('Período CT-e deve usar YYYY-MM.');
        }

        $client = Client::query()
            ->where('office_id', $officeId)
            ->whereKey($clientId)
            ->firstOrFail();
        $establishments = $client->establishments()
            ->where('office_id', $officeId)
            ->pluck('id');
        $cnpjs = $client->establishments()
            ->where('office_id', $officeId)
            ->pluck('cnpj');

        $start = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
        $end = $start->endOfMonth();

        $dfeIds = DocumentInterest::query()
            ->where('office_id', $officeId)
            ->whereIn('establishment_id', $establishments)
            ->pluck('dfe_document_id')
            ->unique()
            ->values();

        $cteQuery = CteDocument::query()
            ->where('office_id', $officeId)
            ->where('is_summary', false)
            ->whereIn('dfe_document_id', $dfeIds)
            ->whereBetween('issued_at', [$start, $end]);
        $cteIds = (clone $cteQuery)->pluck('dfe_document_id')->unique()->values();

        $originalIds = DocumentAcquisition::query()
            ->where('office_id', $officeId)
            ->whereIn('dfe_document_id', $cteIds)
            ->whereIn('artifact_quality', [
                DocumentArtifactQuality::Original->value,
                DocumentArtifactQuality::AutXmlOriginal->value,
            ])
            ->pluck('dfe_document_id')
            ->unique()
            ->values();
        $redactedIds = DocumentAcquisition::query()
            ->where('office_id', $officeId)
            ->whereIn('dfe_document_id', $cteIds)
            ->where('artifact_quality', DocumentArtifactQuality::AutXmlRedacted->value)
            ->whereNotIn('dfe_document_id', $originalIds)
            ->pluck('dfe_document_id')
            ->unique()
            ->values();

        $pendingImport = (clone $cteQuery)
            ->where('coverage_status', CteCoverageStatus::PendingImport->value)
            ->count();
        $blocked = ChannelSyncCursor::query()
            ->where('office_id', $officeId)
            ->whereIn('establishment_id', $establishments)
            ->whereIn('status', [SyncCursorStatus::Blocked->value, SyncCursorStatus::Error->value])
            ->exists()
            || FiscalDocumentQuarantine::query()
                ->where('office_id', $officeId)
                ->whereIn('issuer_cnpj', $cnpjs)
                ->where('resolution_status', QuarantineResolutionStatus::Open->value)
                ->exists();
        $historicalGap = ChannelSyncCursor::query()
            ->where('office_id', $officeId)
            ->whereIn('establishment_id', $establishments)
            ->where('created_at', '>', $end)
            ->exists();

        $status = match (true) {
            $originalIds->isNotEmpty() => CteCoverageStatus::CapturedOriginal,
            $redactedIds->isNotEmpty() => CteCoverageStatus::CapturedAutXmlRedacted,
            $pendingImport > 0 => CteCoverageStatus::PendingImport,
            $blocked => CteCoverageStatus::Blocked,
            $historicalGap => CteCoverageStatus::HistoricalGap,
            default => CteCoverageStatus::NoActivity,
        };

        (clone $cteQuery)->update(['coverage_status' => $status->value]);

        return CteCoverageSnapshot::query()->updateOrCreate(
            [
                'office_id' => $officeId,
                'client_id' => $clientId,
                'period' => $period,
            ],
            [
                'status' => $status,
                'documents_count' => $cteIds->count(),
                'original_count' => $originalIds->count(),
                'autxml_redacted_count' => $redactedIds->count(),
                'pending_import_count' => $pendingImport,
                'metadata' => [
                    'has_blocked_stream' => $blocked,
                    'has_historical_gap' => $historicalGap,
                    'scope' => 'known_documents_only',
                ],
                'computed_at' => now(),
            ],
        );
    }
}
