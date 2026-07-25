<?php

namespace App\Services\Outbound;

use App\Enums\OutboundMonthlyReadinessStatus;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\OutboundUrgencyBand;
use App\Enums\SvrsNfceRecoveryStatus;
use App\Models\MaOutboundRetrievalRequest;
use App\Models\NfeDocument;
use App\Models\OutboundMonthlyReadiness;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Completude sobre documentos conhecidos — nunca garante universo fiscal absoluto.
 */
final class OutboundMonthlyReadinessService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{
     *   known_total: int,
     *   captured_total: int,
     *   pending_total: int,
     *   by_band: array<string, int>,
     *   status: OutboundMonthlyReadinessStatus,
     *   completeness_scope: string
     * }
     */
    public function compute(int $officeId, string $competence): array
    {
        // Prefer recoveries conhecidas da competência (fonte de verdade do planner)
        $recoveries = MaOutboundRetrievalRequest::query()
            ->where('office_id', $officeId)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->where('competence', $competence)
            ->get();

        $knownFromNfe = NfeDocument::query()
            ->where('office_id', $officeId)
            ->where('is_summary', false)
            ->where('direction', 'OUT')
            ->whereNotNull('issued_at')
            ->get()
            ->filter(function (NfeDocument $n) use ($competence): bool {
                try {
                    return CarbonImmutable::parse($n->issued_at)->format('Y-m') === $competence;
                } catch (\Throwable) {
                    return false;
                }
            })
            ->count();

        $known = max($knownFromNfe, $recoveries->count());
        $captured = $recoveries->whereIn('recovery_status', [
            SvrsNfceRecoveryStatus::Captured,
            SvrsNfceRecoveryStatus::ResolvedByOtherSource,
        ])->count();

        // Se não há recoveries, contar NFe OUT capturados no vault como conhecidos capturados
        if ($recoveries->isEmpty() && $knownFromNfe > 0) {
            $captured = $knownFromNfe;
            $known = $knownFromNfe;
        }

        $pending = max(0, $known - $captured);
        $byBand = [];
        foreach (OutboundUrgencyBand::cases() as $band) {
            $byBand[$band->value] = $recoveries->where('urgency_band', $band)->count();
        }

        $status = $pending === 0 && $known > 0
            ? OutboundMonthlyReadinessStatus::CompleteKnown
            : OutboundMonthlyReadinessStatus::NotReady;

        return [
            'known_total' => $known,
            'captured_total' => $captured,
            'pending_total' => $pending,
            'by_band' => $byBand,
            'status' => $status,
            'completeness_scope' => 'known_documents_only',
        ];
    }

    public function refresh(int $officeId, string $competence): OutboundMonthlyReadiness
    {
        $stats = $this->compute($officeId, $competence);

        return DB::transaction(function () use ($officeId, $competence, $stats) {
            $row = OutboundMonthlyReadiness::query()->firstOrNew([
                'office_id' => $officeId,
                'competence' => $competence,
            ]);

            // Não rebaixar PARTIAL_CONFIRMED se já confirmado e ainda há pendências
            $status = $stats['status'];
            if ($row->exists
                && $row->status === OutboundMonthlyReadinessStatus::PartialConfirmed
                && $stats['pending_total'] > 0) {
                $status = OutboundMonthlyReadinessStatus::PartialConfirmed;
            }

            $row->fill([
                'status' => $status,
                'known_total' => $stats['known_total'],
                'captured_total' => $stats['captured_total'],
                'pending_total' => $stats['pending_total'],
                'summary' => [
                    'by_band' => $stats['by_band'],
                    'completeness_scope' => 'known_documents_only',
                ],
            ]);
            $row->save();

            return $row;
        });
    }

    public function confirmPartial(
        int $officeId,
        string $competence,
        int $userId,
        ?string $notes = null,
    ): OutboundMonthlyReadiness {
        $row = $this->refresh($officeId, $competence);
        if ($row->pending_total === 0) {
            $row->status = OutboundMonthlyReadinessStatus::CompleteKnown;
            $row->save();

            return $row;
        }

        $row->forceFill([
            'status' => OutboundMonthlyReadinessStatus::PartialConfirmed,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
            'confirmation_notes' => $notes ? mb_substr($notes, 0, 1000) : null,
        ])->save();

        $this->audit->record('outbound.monthly.partial_confirm', 'SUCCESS', $row, [
            'competence' => $competence,
            'pending_total' => $row->pending_total,
            'known_total' => $row->known_total,
        ], $userId, $officeId);

        return $row;
    }
}
