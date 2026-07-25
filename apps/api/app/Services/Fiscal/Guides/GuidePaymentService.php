<?php

namespace App\Services\Fiscal\Guides;

use App\Contracts\GuideEmissionClient;
use App\Enums\TaxGuidePaymentStatus;
use App\Models\Office;
use App\Models\TaxGuide;
use App\Models\TaxGuidePaymentConfirmation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Fiscal\Guides\DTO\GuidePaymentLookupRequest;
use App\Services\Fiscal\Guides\Exceptions\GuideException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Confirmações oficiais de pagamento — NUNCA inferidas por emissão ou download.
 */
final class GuidePaymentService
{
    public function __construct(
        private readonly GuideEmissionClient $client,
        private readonly GuideStorageService $storage,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Consulta fonte oficial e, se pago, registra evidência.
     *
     * @return array{guide:TaxGuide,confirmation:?TaxGuidePaymentConfirmation,status:string}
     */
    public function lookupAndConfirm(Office $office, TaxGuide $guide, ?User $user = null): array
    {
        $this->assertTenant($office, $guide);

        $version = $guide->currentVersion()->withoutGlobalScopes()->first();

        $lookup = $this->client->lookupPayment(new GuidePaymentLookupRequest(
            officeId: (int) $office->id,
            clientId: (int) $guide->client_id,
            systemCode: $guide->system_code,
            serviceCode: $guide->service_code,
            identifierCode: $guide->identifier_code ?? $version?->identifier_code,
            competencePeriodKey: $guide->competence_period_key,
            debitRef: $guide->debit_ref,
        ));

        if (! $lookup->official) {
            throw new GuideException(
                'Fonte de pagamento não oficial rejeitada.',
                'payment_source_unofficial',
                422,
            );
        }

        if ($lookup->status === 'NOT_FOUND' || $lookup->status === 'NOT_PAID') {
            // Não inventa pagamento; se ainda desconhecido, marca NOT_CONFIRMED
            if ($guide->payment_status === TaxGuidePaymentStatus::Unknown) {
                $guide->payment_status = TaxGuidePaymentStatus::NotConfirmed;
                $guide->save();
            }

            $this->audit->record(
                action: 'tax_guide.payment.lookup',
                result: $lookup->status,
                subject: $guide,
                context: ['official' => true],
                userId: $user?->id,
                officeId: (int) $office->id,
            );

            return ['guide' => $guide->fresh(), 'confirmation' => null, 'status' => $lookup->status];
        }

        if ($lookup->externalId === null || $lookup->externalId === '') {
            throw new GuideException(
                'Confirmação oficial sem identificador externo.',
                'payment_missing_external_id',
                422,
            );
        }

        $confirmation = $this->recordOfficial(
            office: $office,
            guide: $guide,
            source: $lookup->source ?? 'INTEGRA_PAGAMENTO',
            externalId: $lookup->externalId,
            amountCents: $lookup->amountCents,
            paidAtIso: $lookup->paidAtIso,
            evidenceBytes: $lookup->evidenceBytes,
            contentType: $lookup->contentType,
            partial: $lookup->status === 'PARTIAL',
            user: $user,
            versionId: $version?->id,
        );

        return [
            'guide' => $guide->fresh(),
            'confirmation' => $confirmation,
            'status' => $lookup->status,
        ];
    }

    /**
     * Registro explícito de confirmação oficial (adapter/job).
     */
    public function recordOfficial(
        Office $office,
        TaxGuide $guide,
        string $source,
        string $externalId,
        ?int $amountCents,
        ?string $paidAtIso,
        ?string $evidenceBytes,
        ?string $contentType,
        bool $partial,
        ?User $user,
        ?int $versionId = null,
    ): TaxGuidePaymentConfirmation {
        $this->assertTenant($office, $guide);

        $digest = GuideIdempotency::paymentEvidenceDigest($source, $externalId);

        $existing = TaxGuidePaymentConfirmation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('evidence_digest', $digest)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use (
            $office,
            $guide,
            $source,
            $externalId,
            $amountCents,
            $paidAtIso,
            $evidenceBytes,
            $contentType,
            $partial,
            $user,
            $versionId,
            $digest,
        ): TaxGuidePaymentConfirmation {
            $stored = null;
            if ($evidenceBytes !== null && $evidenceBytes !== '') {
                $stored = $this->storage->storePaymentEvidence(
                    (int) $office->id,
                    $evidenceBytes,
                    $contentType ?? 'application/json',
                );
            }

            $confirmation = TaxGuidePaymentConfirmation::query()->create([
                'office_id' => $office->id,
                'tax_guide_id' => $guide->id,
                'tax_guide_version_id' => $versionId ?? $guide->current_version_id,
                'source' => strtoupper($source),
                'external_id' => $externalId,
                'amount_cents' => $amountCents,
                'paid_at' => $paidAtIso ? CarbonImmutable::parse($paidAtIso) : CarbonImmutable::now(),
                'content_sha256' => $stored['content_sha256'] ?? null,
                'vault_object_id' => $stored['vault_object_id'] ?? null,
                'content_type' => $stored['content_type'] ?? null,
                'byte_size' => $stored['byte_size'] ?? 0,
                'evidence_digest' => $digest,
                'metadata' => ['partial' => $partial],
                'recorded_by' => $user?->id,
                'created_at' => CarbonImmutable::now(),
            ]);

            // Atualiza projeção de pagamento sem apagar histórico de emissão
            $guide->forceFill([
                'payment_status' => $partial
                    ? TaxGuidePaymentStatus::Partial
                    : TaxGuidePaymentStatus::Confirmed,
                'payment_confirmed_at' => $confirmation->paid_at,
                'payment_source' => $confirmation->source,
                'payment_external_id' => $confirmation->external_id,
            ])->save();

            $this->audit->record(
                action: 'tax_guide.payment.confirm',
                result: 'SUCCESS',
                subject: $confirmation,
                context: [
                    'tax_guide_id' => $guide->id,
                    'source' => $confirmation->source,
                    'partial' => $partial,
                    'amount_cents' => $amountCents,
                    // sem vault_object_id
                ],
                userId: $user?->id,
                officeId: (int) $office->id,
            );

            return $confirmation;
        });
    }

    /**
     * Download interno NÃO deve chamar este método — documentado via testes.
     */
    public function assertDownloadDoesNotPay(TaxGuide $guide): TaxGuidePaymentStatus
    {
        return $guide->payment_status instanceof TaxGuidePaymentStatus
            ? $guide->payment_status
            : TaxGuidePaymentStatus::from((string) $guide->payment_status);
    }

    private function assertTenant(Office $office, TaxGuide $guide): void
    {
        if ((int) $guide->office_id !== (int) $office->id) {
            throw GuideException::notFound();
        }
    }
}
