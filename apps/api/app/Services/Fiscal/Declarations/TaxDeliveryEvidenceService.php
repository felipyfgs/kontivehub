<?php

namespace App\Services\Fiscal\Declarations;

use App\Enums\FiscalSituation;
use App\Enums\TaxDeliveryEvidenceKind;
use App\Enums\TaxObligationApplicability;
use App\Models\Office;
use App\Models\TaxDeliveryEvidence;
use App\Models\TaxObligationProjection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Evidência de entrega: recibo/protocolo oficial obrigatório para UP_TO_DATE (11.3).
 * Artefato interno sem protocolo permanece não conclusivo.
 */
final class TaxDeliveryEvidenceService
{
    /**
     * Registra evidência e, se conclusiva, marca entrega como UP_TO_DATE.
     *
     * @param  array{
     *   kind: TaxDeliveryEvidenceKind|string,
     *   protocol_number?: string|null,
     *   receipt_number?: string|null,
     *   source: string,
     *   source_version?: string|null,
     *   observed_at?: CarbonImmutable|null,
     *   evidence_artifact_id?: int|null,
     *   run_id?: int|null,
     *   payload_digest?: string|null,
     *   metadata?: array<string, mixed>|null
     * }  $input
     */
    public function attach(Office $office, TaxObligationProjection $projection, array $input): TaxDeliveryEvidence
    {
        if ((int) $projection->office_id !== (int) $office->id) {
            throw new RuntimeException('Projeção não pertence ao escritório ativo.');
        }

        $kind = $input['kind'] instanceof TaxDeliveryEvidenceKind
            ? $input['kind']
            : TaxDeliveryEvidenceKind::from(strtoupper((string) $input['kind']));

        $protocol = $this->normalizeRef($input['protocol_number'] ?? null);
        $receipt = $this->normalizeRef($input['receipt_number'] ?? null);
        $isConclusive = $this->isConclusive($kind, $protocol, $receipt);

        return DB::transaction(function () use (
            $office,
            $projection,
            $kind,
            $protocol,
            $receipt,
            $isConclusive,
            $input,
        ) {
            $locked = TaxObligationProjection::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->whereKey($projection->id)
                ->lockForUpdate()
                ->firstOrFail();

            $evidence = TaxDeliveryEvidence::query()->create([
                'office_id' => $office->id,
                'projection_id' => $locked->id,
                'kind' => $kind,
                'protocol_number' => $protocol,
                'receipt_number' => $receipt,
                'is_conclusive' => $isConclusive,
                'source' => (string) $input['source'],
                'source_version' => $input['source_version'] ?? null,
                'observed_at' => $input['observed_at'] ?? CarbonImmutable::now(),
                'evidence_artifact_id' => $input['evidence_artifact_id'] ?? null,
                'run_id' => $input['run_id'] ?? null,
                'payload_digest' => $input['payload_digest'] ?? null,
                'metadata' => array_merge($input['metadata'] ?? [], [
                    'conclusive_reason' => $isConclusive
                        ? 'OFFICIAL_WITH_PROTOCOL_OR_RECEIPT'
                        : $this->nonConclusiveReason($kind, $protocol, $receipt),
                ]),
            ]);

            if ($isConclusive) {
                $this->markDelivered($locked, $evidence);
            } else {
                $this->markNonConclusiveArtifact($locked, $evidence);
            }

            return $evidence->fresh();
        });
    }

    /**
     * Oficial + (protocolo OU recibo) = conclusivo.
     * INTERNAL_ARTIFACT nunca é conclusivo, mesmo com número livre.
     * Resposta oficial sem número também não é conclusiva.
     */
    public function isConclusive(
        TaxDeliveryEvidenceKind $kind,
        ?string $protocolNumber,
        ?string $receiptNumber,
    ): bool {
        if (! $kind->canBeConclusive()) {
            return false;
        }

        $hasRef = ($protocolNumber !== null && $protocolNumber !== '')
            || ($receiptNumber !== null && $receiptNumber !== '');

        return $hasRef;
    }

    public function markDelivered(
        TaxObligationProjection $projection,
        TaxDeliveryEvidence $evidence,
    ): TaxObligationProjection {
        if (! $evidence->is_conclusive) {
            throw new RuntimeException('Evidência não conclusiva não pode marcar entrega.');
        }
        if ((int) $evidence->projection_id !== (int) $projection->id) {
            throw new RuntimeException('Evidência não pertence à projeção.');
        }
        if ((int) $evidence->office_id !== (int) $projection->office_id) {
            throw new RuntimeException('Tenant da evidência diverge da projeção.');
        }

        // NOT_APPLICABLE / UNSUPPORTED não viram UP_TO_DATE por recibo acidental.
        if (in_array($projection->applicability, [
            TaxObligationApplicability::NotApplicable,
            TaxObligationApplicability::Unsupported,
        ], true)) {
            throw new RuntimeException(
                'Não é possível marcar entrega em obrigação não aplicável ou não suportada.'
            );
        }

        $projection->forceFill([
            'situation' => FiscalSituation::UpToDate,
            'delivery_status' => FiscalSituation::UpToDate,
            'conclusive_evidence_id' => $evidence->id,
            'evidence_artifact_id' => $evidence->evidence_artifact_id ?? $projection->evidence_artifact_id,
            'is_open' => false,
            'closed_at' => $projection->closed_at ?? CarbonImmutable::now(),
            'metadata' => array_merge($projection->metadata ?? [], [
                'delivered_at' => CarbonImmutable::now()->toIso8601String(),
                'delivery_evidence_id' => $evidence->id,
                'delivery_kind' => $evidence->kind?->value,
            ]),
        ])->save();

        return $projection->fresh(['obligation', 'conclusiveEvidence']);
    }

    /**
     * Artefato interno / oficial sem protocolo: registra vínculo, mantém entrega não conclusiva.
     */
    private function markNonConclusiveArtifact(
        TaxObligationProjection $projection,
        TaxDeliveryEvidence $evidence,
    ): void {
        $meta = $projection->metadata ?? [];
        $internal = $meta['internal_artifacts'] ?? [];
        $internal[] = [
            'evidence_id' => $evidence->id,
            'kind' => $evidence->kind?->value,
            'observed_at' => $evidence->observed_at?->toIso8601String(),
            'is_conclusive' => false,
        ];
        $meta['internal_artifacts'] = $internal;
        $meta['last_non_conclusive_evidence_id'] = $evidence->id;

        // Mantém PENDING se aplicável; UNKNOWN se aplicabilidade desconhecida; nunca UP_TO_DATE.
        $delivery = $projection->delivery_status;
        if ($delivery === FiscalSituation::UpToDate && $projection->conclusive_evidence_id === null) {
            $delivery = $projection->applicability === TaxObligationApplicability::Applicable
                ? FiscalSituation::Pending
                : FiscalSituation::Unknown;
        }

        if ($projection->conclusive_evidence_id === null
            && $projection->applicability === TaxObligationApplicability::Applicable
            && $delivery !== FiscalSituation::Pending
        ) {
            $delivery = FiscalSituation::Pending;
        }

        $projection->forceFill([
            'delivery_status' => $delivery,
            'situation' => $projection->applicability === TaxObligationApplicability::Applicable
                ? FiscalSituation::Pending
                : ($projection->situation ?? FiscalSituation::Unknown),
            'evidence_artifact_id' => $evidence->evidence_artifact_id ?? $projection->evidence_artifact_id,
            'metadata' => $meta,
            // permanece aberta
            'is_open' => $projection->is_open || $projection->conclusive_evidence_id === null,
        ])->save();
    }

    private function nonConclusiveReason(
        TaxDeliveryEvidenceKind $kind,
        ?string $protocol,
        ?string $receipt,
    ): string {
        if ($kind === TaxDeliveryEvidenceKind::InternalArtifact) {
            return 'INTERNAL_ARTIFACT_WITHOUT_OFFICIAL_CONFIRMATION';
        }
        if (($protocol === null || $protocol === '') && ($receipt === null || $receipt === '')) {
            return 'OFFICIAL_RESPONSE_WITHOUT_PROTOCOL_OR_RECEIPT';
        }

        return 'NOT_CONCLUSIVE';
    }

    private function normalizeRef(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trim = trim($value);

        return $trim === '' ? null : $trim;
    }
}
