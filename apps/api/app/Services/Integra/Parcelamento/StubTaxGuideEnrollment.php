<?php

namespace App\Services\Integra\Parcelamento;

use App\Contracts\TaxGuideEnrollment;
use App\Enums\TaxGuideEmissionStatus;
use App\Enums\TaxGuidePaymentStatus;
use App\Enums\TaxGuideRiskLevel;
use App\Models\Client;
use App\Models\Office;
use App\Models\TaxGuide;
use App\Models\TaxGuideVersion;
use App\Models\TaxInstallmentParcel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Integra documentos de parcela à central de guias (schema 11.x).
 * Idempotente por office + logical_key; pagamento permanece independente.
 */
final class StubTaxGuideEnrollment implements TaxGuideEnrollment
{
    public function enrollFromInstallmentDocument(
        Office $office,
        Client $client,
        TaxInstallmentParcel $parcel,
        array $document,
    ): array {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório.');
        }
        if ((int) $parcel->office_id !== (int) $office->id
            || (int) $parcel->client_id !== (int) $client->id) {
            throw new RuntimeException('Parcela de outro tenant/contribuinte.');
        }

        $modality = (string) ($document['modality'] ?? $parcel->modality?->value ?? '');
        $orderExt = (string) ($document['order_external_id'] ?? $parcel->order?->external_order_id ?? '');
        $parcelKey = (string) ($document['parcel_key'] ?? $parcel->parcel_key);
        $logical = $this->logicalKey($modality, $orderExt, $parcelKey, $document);
        $versionIdem = hash('sha256', 'v|'.$logical);

        return DB::transaction(function () use (
            $office,
            $client,
            $parcel,
            $document,
            $logical,
            $versionIdem,
            $modality,
            $parcelKey,
        ) {
            $existingVersion = TaxGuideVersion::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('idempotency_key', $versionIdem)
                ->lockForUpdate()
                ->first();

            if ($existingVersion !== null
                && $existingVersion->emission_status === TaxGuideEmissionStatus::Confirmed
                && ($existingVersion->valid_until === null || ! $existingVersion->valid_until->isPast())
            ) {
                $guide = TaxGuide::query()
                    ->withoutGlobalScopes()
                    ->where('office_id', $office->id)
                    ->whereKey($existingVersion->tax_guide_id)
                    ->firstOrFail();

                if ($parcel->tax_guide_id !== $guide->id) {
                    $parcel->forceFill(['tax_guide_id' => $guide->id])->save();
                }

                return ['guide' => $guide->fresh(['currentVersion']), 'reused' => true];
            }

            $guide = TaxGuide::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('logical_key', $logical)
                ->lockForUpdate()
                ->first();

            if ($guide === null) {
                $guide = TaxGuide::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $client->id,
                    'system_code' => (string) ($document['source_system'] ?? ParcelamentoServiceCatalog::SOLUTION),
                    'service_code' => (string) ($document['source_service'] ?? $modality),
                    'operation_code' => (string) ($document['source_operation'] ?? 'EMITIR_DOCUMENTO'),
                    'competence_period_key' => $parcelKey,
                    'debit_ref' => $parcelKey,
                    'logical_key' => $logical,
                    // Emissão NÃO marca pago
                    'payment_status' => TaxGuidePaymentStatus::Unknown,
                    'amount_cents' => isset($document['amount_cents'])
                        ? (int) $document['amount_cents']
                        : $parcel->amount_cents,
                    'due_at' => isset($document['due_at'])
                        ? CarbonImmutable::parse((string) $document['due_at'])
                        : $parcel->due_at,
                    'identifier_code' => $document['identifier'] ?? null,
                    'metadata' => [
                        'source_module' => 'parcelamentos',
                        'installment_order_id' => $parcel->order_id,
                        'installment_parcel_id' => $parcel->id,
                        'payment_independent' => true,
                    ],
                ]);
            }

            $previous = TaxGuideVersion::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('tax_guide_id', $guide->id)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            $nextVersion = ($previous?->version_number ?? 0) + 1;
            $validUntil = isset($document['valid_until'])
                ? CarbonImmutable::parse((string) $document['valid_until'])
                : CarbonImmutable::now()->addDays(5);

            $version = TaxGuideVersion::query()->create([
                'office_id' => $office->id,
                'tax_guide_id' => $guide->id,
                'version_number' => $nextVersion,
                'is_current' => true,
                'emission_status' => TaxGuideEmissionStatus::Confirmed,
                'replaces_version_id' => $previous?->id,
                'identifier_code' => $document['identifier'] ?? $guide->identifier_code,
                'amount_cents' => isset($document['amount_cents'])
                    ? (int) $document['amount_cents']
                    : $guide->amount_cents,
                'currency' => 'BRL',
                'due_at' => isset($document['due_at'])
                    ? CarbonImmutable::parse((string) $document['due_at'])
                    : $guide->due_at,
                'valid_until' => $validUntil,
                'content_sha256' => $document['content_sha256'] ?? null,
                'vault_object_id' => $document['vault_object_id'] ?? null,
                'content_type' => $document['content_type'] ?? 'application/pdf',
                'byte_size' => isset($document['byte_size']) ? (int) $document['byte_size'] : 0,
                'idempotency_key' => $versionIdem,
                'correlation_id' => $document['correlation_id'] ?? null,
                'risk_level' => TaxGuideRiskLevel::Standard,
                'finished_at' => CarbonImmutable::now(),
                'metadata' => array_merge($document['metadata'] ?? [], [
                    'source_module' => 'parcelamentos',
                    'payment_independent' => true,
                ]),
            ]);

            if ($previous !== null) {
                $previous->forceFill([
                    'is_current' => false,
                    'emission_status' => TaxGuideEmissionStatus::Superseded,
                    'superseded_by_version_id' => $version->id,
                ])->save();
            }

            // Não sobrescreve CONFIRMED de pagamento; só normaliza UNKNOWN → NOT_CONFIRMED
            $paymentStatus = $guide->payment_status;
            if ($paymentStatus === TaxGuidePaymentStatus::Unknown || $paymentStatus === null) {
                $paymentStatus = TaxGuidePaymentStatus::NotConfirmed;
            }

            $guide->forceFill([
                'current_version_id' => $version->id,
                'amount_cents' => $version->amount_cents,
                'due_at' => $version->due_at,
                'identifier_code' => $version->identifier_code,
                'payment_status' => $paymentStatus,
            ])->save();

            $parcel->forceFill([
                'tax_guide_id' => $guide->id,
                'document_available' => true,
            ])->save();

            return ['guide' => $guide->fresh(['currentVersion']), 'reused' => false];
        });
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function logicalKey(
        string $modality,
        string $orderExternalId,
        string $parcelKey,
        array $document = [],
    ): string {
        $parts = [
            'parcelamentos',
            strtoupper($modality),
            $orderExternalId,
            $parcelKey,
            (string) ($document['document_type'] ?? 'DAS'),
        ];

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function idempotencyKey(
        string $modality,
        string $orderExternalId,
        string $parcelKey,
        array $document = [],
    ): string {
        return hash('sha256', 'v|'.$this->logicalKey($modality, $orderExternalId, $parcelKey, $document));
    }
}
