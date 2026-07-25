<?php

namespace App\Services\Sefaz;

use App\Enums\CteCoverageStatus;
use App\Enums\DocumentArtifactQuality;
use App\Enums\QuarantineReason;
use App\Enums\QuarantineResolutionStatus;
use App\Models\CteDocument;
use App\Models\CteEvent;
use App\Models\DocumentAcquisition;
use App\Models\DocumentInterest;
use App\Models\Establishment;
use App\Models\FiscalDocumentQuarantine;
use Illuminate\Support\Facades\DB;

/** Reconcilia eventos órfãos, pendências de import e qualidade canônica por chave. */
final class CteReconciliationService
{
    public function __construct(
        private readonly CteCoverageService $coverage = new CteCoverageService,
    ) {}

    /** @return array{events_linked: int, quarantines_resolved: int, coverage_recomputed: int} */
    public function reconcileDocument(int $officeId, string $accessKey): array
    {
        $accessKey = strtoupper(trim($accessKey));

        return DB::transaction(function () use ($officeId, $accessKey): array {
            $parent = CteDocument::query()
                ->where('office_id', $officeId)
                ->where('access_key', $accessKey)
                ->where('is_summary', false)
                ->first();
            if ($parent === null) {
                return ['events_linked' => 0, 'quarantines_resolved' => 0, 'coverage_recomputed' => 0];
            }

            $events = CteEvent::query()
                ->where('office_id', $officeId)
                ->where('access_key', $accessKey)
                ->whereNull('cte_document_id')
                ->update(['cte_document_id' => $parent->id]);

            $quarantines = FiscalDocumentQuarantine::query()
                ->where('office_id', $officeId)
                ->where('access_key', $accessKey)
                ->where('resolution_status', QuarantineResolutionStatus::Open->value)
                ->whereIn('reason', [
                    QuarantineReason::OrphanEvent->value,
                    QuarantineReason::PendingImport->value,
                    QuarantineReason::UnmatchedIssuer->value,
                    QuarantineReason::EnrollmentMissing->value,
                ])
                ->get();
            foreach ($quarantines as $quarantine) {
                // UnmatchedIssuer só resolve se o emitente agora existe no office
                if (in_array($quarantine->reason, [
                    QuarantineReason::UnmatchedIssuer,
                    QuarantineReason::EnrollmentMissing,
                ], true)) {
                    $issuer = $quarantine->issuer_cnpj;
                    if ($issuer === null || $issuer === '') {
                        continue;
                    }
                    $hasIssuer = DocumentInterest::query()
                        ->join('establishments', 'establishments.id', '=', 'document_interests.establishment_id')
                        ->where('document_interests.office_id', $officeId)
                        ->where('document_interests.dfe_document_id', $parent->dfe_document_id)
                        ->where('establishments.cnpj', strtoupper($issuer))
                        ->exists()
                        || Establishment::query()
                            ->where('office_id', $officeId)
                            ->where('cnpj', strtoupper($issuer))
                            ->where('is_active', true)
                            ->exists();
                    if (! $hasIssuer) {
                        continue;
                    }
                }

                $metadata = $quarantine->metadata ?? [];
                $metadata['previous_reason'] = $quarantine->reason->value;
                $metadata['resolved_by_source'] = 'CANONICAL_CTE_AVAILABLE';
                $quarantine->update([
                    'resolution_status' => QuarantineResolutionStatus::Resolved,
                    'resolved_at' => now(),
                    'resolution_code' => 'RECONCILED_AFTER_IMPORT',
                    'promoted_dfe_document_id' => $parent->dfe_document_id,
                    'metadata' => $metadata,
                ]);
            }

            $bestQuality = DocumentAcquisition::query()
                ->where('office_id', $officeId)
                ->where(function ($q) use ($parent, $accessKey): void {
                    $q->where('dfe_document_id', $parent->dfe_document_id)
                        ->orWhere('access_key', $accessKey);
                })
                ->whereIn('artifact_quality', [
                    DocumentArtifactQuality::Original->value,
                    DocumentArtifactQuality::AutXmlOriginal->value,
                ])
                ->exists()
                    ? CteCoverageStatus::CapturedOriginal
                    : CteCoverageStatus::CapturedAutXmlRedacted;
            $parent->update(['coverage_status' => $bestQuality]);

            $clientIds = DocumentInterest::query()
                ->join('establishments', 'establishments.id', '=', 'document_interests.establishment_id')
                ->where('document_interests.office_id', $officeId)
                ->where('document_interests.dfe_document_id', $parent->dfe_document_id)
                ->pluck('establishments.client_id')
                ->unique();
            $period = $parent->issued_at?->format('Y-m');
            $coverageCount = 0;
            if ($period !== null) {
                foreach ($clientIds as $clientId) {
                    $this->coverage->recompute($officeId, (int) $clientId, $period);
                    $coverageCount++;
                }
            }

            return [
                'events_linked' => $events,
                'quarantines_resolved' => $quarantines->filter(
                    fn ($q) => $q->fresh()->resolution_status === QuarantineResolutionStatus::Resolved
                )->count(),
                'coverage_recomputed' => $coverageCount,
            ];
        });
    }

    /**
     * Lote: reconcilia chaves com eventos órfãos ou quarentenas abertas associáveis
     * após cadastro de cliente/emitente ou import — sempre escopado por office_id.
     *
     * @return array{
     *   keys_processed: int,
     *   events_linked: int,
     *   quarantines_resolved: int,
     *   coverage_recomputed: int
     * }
     */
    public function reconcileOrphans(int $officeId, int $limit = 200): array
    {
        $limit = max(1, min(2000, $limit));

        $keysFromEvents = CteEvent::query()
            ->where('office_id', $officeId)
            ->whereNull('cte_document_id')
            ->whereNotNull('access_key')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('access_key');

        $keysFromQuarantine = FiscalDocumentQuarantine::query()
            ->where('office_id', $officeId)
            ->where('resolution_status', QuarantineResolutionStatus::Open->value)
            ->whereIn('reason', [
                QuarantineReason::OrphanEvent->value,
                QuarantineReason::PendingImport->value,
                QuarantineReason::UnmatchedIssuer->value,
            ])
            ->whereNotNull('access_key')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('access_key');

        $keys = $keysFromEvents->merge($keysFromQuarantine)
            ->map(fn ($k) => strtoupper(trim((string) $k)))
            ->filter()
            ->unique()
            ->take($limit)
            ->values();

        $totals = [
            'keys_processed' => 0,
            'events_linked' => 0,
            'quarantines_resolved' => 0,
            'coverage_recomputed' => 0,
        ];

        foreach ($keys as $key) {
            $result = $this->reconcileDocument($officeId, $key);
            $totals['keys_processed']++;
            $totals['events_linked'] += $result['events_linked'];
            $totals['quarantines_resolved'] += $result['quarantines_resolved'];
            $totals['coverage_recomputed'] += $result['coverage_recomputed'];
        }

        return $totals;
    }
}
