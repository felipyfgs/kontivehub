<?php

namespace App\Services\Outbound;

use App\Enums\OutboundDeadlineStatus;
use App\Enums\OutboundFiscalModel;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\OutboundUrgencyBand;
use App\Enums\SvrsNfceFailureReason;
use App\Enums\SvrsNfceRecoveryStatus;
use App\Models\DfeDocument;
use App\Models\MaOutboundRetrievalRequest;
use App\Models\NfeDocument;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Satisfação de prazo por qualquer fonte válida; cancela SVRS pendente.
 * Nunca move documento entre tenants; nunca sobrescreve canônico divergente.
 */
final class OutboundDeadlineSatisfactionService
{
    public function __construct(
        private readonly OutboundDeadlineCalculator $calculator,
        private readonly OutboundXmlRecoveryOrchestrator $orchestrator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Após ingestão canônica completa (vault + acquisition + projeção).
     */
    public function markCapturedBySource(
        int $officeId,
        string $accessKey,
        string $sourceLabel,
        ?string $sha256 = null,
        ?int $dfeDocumentId = null,
        ?CarbonImmutable $capturedAt = null,
    ): void {
        $key = strtoupper(preg_replace('/\s+/', '', $accessKey) ?? '');
        if (strlen($key) < 44) {
            return;
        }

        $capturedAt = ($capturedAt ?? CarbonImmutable::now('UTC'))->utc();

        // Recalcula prazo definitivo se houver data de autorização na projeção
        $nfe = NfeDocument::query()
            ->where('office_id', $officeId)
            ->where('access_key', $key)
            ->where('is_summary', false)
            ->first();

        $plan = null;
        if ($nfe?->issued_at) {
            $plan = $this->calculator->planFromAuthorizationDate(
                CarbonImmutable::parse($nfe->issued_at),
                null,
                $capturedAt,
                captured: true,
            );
        } else {
            $plan = $this->calculator->planFromAccessKey($key, null, $capturedAt, captured: true);
        }

        MaOutboundRetrievalRequest::withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('access_key', $key)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereNotIn('recovery_status', [
                SvrsNfceRecoveryStatus::Captured->value,
            ])
            ->each(function (MaOutboundRetrievalRequest $req) use ($plan, $capturedAt, $sourceLabel, $sha256, $dfeDocumentId): void {
                $beforeDue = $plan !== null && $capturedAt->lessThanOrEqualTo($plan->dueAt);

                $req->forceFill([
                    'recovery_status' => $sourceLabel === 'SVRS_PORTAL' || $sourceLabel === 'SVRS_NFCE'
                        ? SvrsNfceRecoveryStatus::Captured
                        : SvrsNfceRecoveryStatus::ResolvedByOtherSource,
                    'failure_reason' => $sourceLabel === 'SVRS_PORTAL' || $sourceLabel === 'SVRS_NFCE'
                        ? null
                        : SvrsNfceFailureReason::CapturedByOther,
                    'urgency_band' => OutboundUrgencyBand::Captured,
                    'deadline_status' => $beforeDue ? OutboundDeadlineStatus::Met : OutboundDeadlineStatus::Missed,
                    'captured_at' => $capturedAt,
                    'captured_before_due' => $beforeDue,
                    'capture_source' => mb_substr($sourceLabel, 0, 40),
                    'next_attempt_at' => null,
                    'sha256' => $sha256 ?? $req->sha256,
                    'dfe_document_id' => $dfeDocumentId ?? $req->dfe_document_id,
                    'ingested_at' => $req->ingested_at ?? $capturedAt,
                    'last_error' => null,
                    'competence' => $plan?->competence->value() ?? $req->competence,
                    'due_at' => $plan?->dueAt ?? $req->due_at,
                    'target_at' => $plan?->targetAt ?? $req->target_at,
                    'deadline_source' => $plan?->source ?? $req->deadline_source,
                    'capacity_at_risk' => false,
                ])->save();
            });

        // Também cancela via orchestrator (jobs/slots)
        $this->orchestrator->resolveByOtherSource($officeId, $key, $sourceLabel);

        $this->audit->record('outbound.deadline.captured', 'SUCCESS', null, [
            'office_id' => $officeId,
            'key_mask' => substr($key, 0, 6).'...'.substr($key, -4),
            'source' => $sourceLabel,
            'before_due' => $plan !== null && $capturedAt->lessThanOrEqualTo($plan->dueAt),
            // sem XML, sem chave completa
        ], null, $officeId);

        Log::info('outbound.deadline.captured', [
            'office_id' => $officeId,
            'source' => $sourceLabel,
            'key_mask' => substr($key, 0, 6).'...'.substr($key, -4),
        ]);
    }

    /**
     * Preferência de fontes antes de SVRS (vault/catálogo).
     *
     * @return array{has_full: bool, source: ?string, dfe_document_id: ?int, sha256: ?string, diverge: bool}
     */
    public function preferExistingSource(int $officeId, string $accessKey): array
    {
        $key = strtoupper(preg_replace('/\s+/', '', $accessKey) ?? '');

        $nfe = NfeDocument::query()
            ->where('office_id', $officeId)
            ->where('access_key', $key)
            ->where('is_summary', false)
            ->first();

        if ($nfe !== null && $nfe->dfe_document_id) {
            $dfe = DfeDocument::query()->where('office_id', $officeId)->find($nfe->dfe_document_id);
            if ($dfe !== null) {
                return [
                    'has_full' => true,
                    'source' => 'CATALOG_FULL',
                    'dfe_document_id' => $dfe->id,
                    'sha256' => $dfe->sha256,
                    'diverge' => false,
                ];
            }
        }

        $dfe = DfeDocument::query()
            ->where('office_id', $officeId)
            ->where('access_key', $key)
            ->first();

        if ($dfe !== null) {
            return [
                'has_full' => true,
                'source' => 'VAULT_DFE',
                'dfe_document_id' => $dfe->id,
                'sha256' => $dfe->sha256,
                'diverge' => false,
            ];
        }

        return [
            'has_full' => false,
            'source' => null,
            'dfe_document_id' => null,
            'sha256' => null,
            'diverge' => false,
        ];
    }

    /**
     * Recusa contagem de resumo como full capturado.
     */
    public function isFullCaptureEligible(?NfeDocument $nfe): bool
    {
        if ($nfe === null) {
            return false;
        }
        if ($nfe->is_summary) {
            return false;
        }
        $status = strtoupper((string) ($nfe->status ?? ''));
        if (in_array($status, ['SUMMARY', 'UNKNOWN'], true)) {
            return false;
        }

        return $nfe->dfe_document_id !== null;
    }

    /**
     * Detecta divergência de bytes para a mesma chave (não sobrescreve canônico).
     */
    public function bytesDivergeFromCanonical(int $officeId, string $accessKey, string $sha256): bool
    {
        $key = strtoupper(preg_replace('/\s+/', '', $accessKey) ?? '');
        $dfe = DfeDocument::query()
            ->where('office_id', $officeId)
            ->where('access_key', $key)
            ->first();

        if ($dfe === null) {
            return false;
        }

        return $dfe->sha256 !== $sha256;
    }

    /**
     * Lote de contingência por office/raiz/modelo (metadados sanitizados).
     *
     * @return list<array<string, mixed>>
     */
    public function contingencyBatch(int $officeId, ?string $competence = null, int $limit = 100): array
    {
        $q = MaOutboundRetrievalRequest::query()
            ->where('office_id', $officeId)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereIn('urgency_band', [
                OutboundUrgencyBand::Contingency->value,
                OutboundUrgencyBand::Overdue->value,
                OutboundUrgencyBand::Attention->value,
            ])
            ->whereNotIn('recovery_status', [
                SvrsNfceRecoveryStatus::Captured->value,
                SvrsNfceRecoveryStatus::ResolvedByOtherSource->value,
            ])
            ->orderBy('due_at')
            ->limit($limit);

        if ($competence) {
            $q->where('competence', $competence);
        }

        return $q->get()->map(function (MaOutboundRetrievalRequest $r) {
            $key = (string) $r->access_key;

            return [
                'id' => $r->id,
                'competence' => $r->competence,
                'model' => $r->model instanceof OutboundFiscalModel ? $r->model->value : $r->model,
                'root_cnpj' => $r->root_cnpj,
                'urgency_band' => $r->urgency_band?->value,
                'due_at' => $r->due_at?->toIso8601String(),
                'target_at' => $r->target_at?->toIso8601String(),
                'access_key_masked' => strlen($key) >= 10
                    ? substr($key, 0, 6).'…'.substr($key, -4)
                    : null,
                'recommended_action' => 'ASSISTED_IMPORT_OR_PACKAGE',
                'svrs_transaction_count' => $r->svrs_transaction_count,
                // sem outro tenant
            ];
        })->all();
    }
}
