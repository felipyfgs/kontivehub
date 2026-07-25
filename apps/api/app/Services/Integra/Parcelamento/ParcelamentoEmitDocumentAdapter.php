<?php

namespace App\Services\Integra\Parcelamento;

use App\Contracts\FiscalSourceAdapter;
use App\Contracts\ParcelamentoSource;
use App\Contracts\TaxGuideEnrollment;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\TaxGuideEmissionStatus;
use App\Enums\TaxInstallmentParcelStatus;
use App\Models\TaxInstallmentOrder;
use App\Models\TaxInstallmentParcel;
use App\Services\Integra\TaxProxyPowerService;
use App\Support\FeatureFlags;
use Carbon\CarbonImmutable;

/**
 * Emissão assistida/idempotente de documento de parcela → central de guias.
 * NÃO marca pagamento. Timeout pós-envio → UNKNOWN_RESULT (sem retry cego).
 */
final class ParcelamentoEmitDocumentAdapter implements FiscalSourceAdapter
{
    public function __construct(
        private readonly ParcelamentoSource $source,
        private readonly TaxGuideEnrollment $guides,
        private readonly TaxProxyPowerService $proxyPowers,
    ) {}

    public function systemCode(): string
    {
        return ParcelamentoServiceCatalog::SOLUTION;
    }

    public function serviceCode(): string
    {
        return '*';
    }

    public function operationCode(): string
    {
        return 'EMITIR_DOCUMENTO';
    }

    public function mutability(): FiscalMutability
    {
        // Assistida — não é adesão; núcleo não trata como MUTATING global
        return FiscalMutability::ReadOnly;
    }

    public function coverage(): FiscalCoverage
    {
        return FiscalCoverage::Full;
    }

    public function moduleKey(): ?string
    {
        return ParcelamentoServiceCatalog::MODULE_KEY;
    }

    public function supports(FiscalAdapterRequest $request): bool
    {
        return strcasecmp($request->systemCode, ParcelamentoServiceCatalog::SOLUTION) === 0
            && ParcelamentoServiceCatalog::parseModality($request->serviceCode) !== null
            && strcasecmp($request->operationCode, 'EMITIR_DOCUMENTO') === 0;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        $modality = ParcelamentoServiceCatalog::parseModality($request->serviceCode);
        if ($modality === null) {
            return FiscalAdapterResult::unsupported("Modalidade inválida: {$request->serviceCode}");
        }

        if (! FeatureFlags::isModuleEnabled(ParcelamentoServiceCatalog::MODULE_KEY, $request->office->id)
            && ! (bool) config('fiscal_monitoring.enabled', false)) {
            return FiscalAdapterResult::blocked('Módulo parcelamentos desabilitado.', 'FEATURE_DISABLED');
        }

        $author = (string) ($request->context['author_identity'] ?? '');
        if ($author !== '') {
            $power = $this->proxyPowers->findUsablePower(
                (int) $request->office->id,
                (int) $request->client->id,
                $modality->requiredPowerCode(),
                $author,
            );
            if ($power === null) {
                return FiscalAdapterResult::blocked(
                    "Sem poder {$modality->requiredPowerCode()} para emitir documento.",
                    'PROXY_POWER_MISSING',
                );
            }
        } elseif (! empty($request->context['require_proxy_power'])) {
            return FiscalAdapterResult::blocked(
                'Poder de procuração obrigatório para emissão.',
                'PROXY_POWER_MISSING',
            );
        }

        $parcelKey = (string) ($request->context['parcel_key']
            ?? $request->context['parcelaParaEmitir']
            ?? '');
        $orderExternalId = (string) ($request->context['order_external_id']
            ?? $request->context['numeroParcelamento']
            ?? '');

        if ($parcelKey === '') {
            return FiscalAdapterResult::failed(
                'parcel_key/parcelaParaEmitir é obrigatório.',
                'MISSING_PARCEL_KEY',
            );
        }

        $order = null;
        $parcel = null;
        if ($orderExternalId !== '') {
            $order = TaxInstallmentOrder::query()
                ->withoutGlobalScopes()
                ->where('office_id', $request->office->id)
                ->where('client_id', $request->client->id)
                ->where('modality', $modality->value)
                ->where('external_order_id', $orderExternalId)
                ->first();
        }

        if ($order !== null) {
            $parcel = TaxInstallmentParcel::query()
                ->withoutGlobalScopes()
                ->where('office_id', $request->office->id)
                ->where('order_id', $order->id)
                ->where('parcel_key', $parcelKey)
                ->first();
        }

        // Reuso de guia válida (idempotência) antes de chamar fonte
        if ($parcel?->tax_guide_id) {
            $existingGuide = $parcel->taxGuide;
            $current = $existingGuide?->currentVersion;
            if ($existingGuide !== null
                && $current !== null
                && $current->emission_status?->isReusable()
                && ($current->valid_until === null || ! $current->valid_until->isPast())
            ) {
                $evidence = json_encode([
                    'reused' => true,
                    'guide_id' => $existingGuide->id,
                    'modality' => $modality->value,
                    'parcel_key' => $parcelKey,
                ], JSON_THROW_ON_ERROR);

                return new FiscalAdapterResult(
                    result: FiscalRunResult::Success,
                    situation: FiscalSituation::UpToDate,
                    coverage: FiscalCoverage::Full,
                    evidenceBytes: $evidence,
                    normalized: [
                        'reused' => true,
                        'guide' => $existingGuide->toPublicArray(),
                        'payment_status' => $existingGuide->payment_status?->value,
                    ],
                    findings: [[
                        'code' => 'GUIA_REUTILIZADA',
                        'severity' => 'INFO',
                        'title' => 'Documento de parcela reutilizado',
                        'detail' => 'Guia válida existente — sem nova emissão remota.',
                        'creates_pending' => false,
                    ]],
                    itemsProcessed: 1,
                );
            }
        }

        $response = $this->source->execute($modality, 'EMITIR_DOCUMENTO', [
            'parcelaParaEmitir' => $parcelKey,
            'numeroParcelamento' => $orderExternalId,
        ], $request);

        if (! empty($response['timeout_uncertain'])) {
            $evidence = json_encode($response, JSON_THROW_ON_ERROR);

            return new FiscalAdapterResult(
                result: FiscalRunResult::Failed,
                situation: FiscalSituation::Unknown,
                coverage: FiscalCoverage::Full,
                evidenceBytes: $evidence,
                normalized: [
                    'uncertain' => true,
                    'emission_status' => TaxGuideEmissionStatus::UnknownResult->value,
                    'modality' => $modality->value,
                    'parcel_key' => $parcelKey,
                    'retry_blocked' => true,
                ],
                findings: [[
                    'code' => 'EMIT_TIMEOUT_UNCERTAIN',
                    'severity' => 'HIGH',
                    'title' => 'Timeout após possível emissão',
                    'detail' => 'Resultado incerto — reconciliar antes de repetir.',
                    'situation' => FiscalSituation::Unknown->value,
                    'creates_pending' => true,
                ]],
                errorCode: 'TIMEOUT_AFTER_SEND',
                errorMessage: $response['error_message'] ?? 'Timeout incerto',
            );
        }

        if (! $response['success']) {
            return FiscalAdapterResult::failed(
                $response['error_message'] ?? 'Falha na emissão',
                $response['error_code'] ?? 'EMIT_FAILED',
            );
        }

        $isTrial = (bool) ($response['simulated'] ?? false);

        $doc = $response['body']['documento'] ?? [];
        $raw = isset($doc['conteudoBase64'])
            ? (base64_decode((string) $doc['conteudoBase64'], true) ?: '')
            : '';
        $sha = $raw !== '' ? hash('sha256', $raw) : hash('sha256', json_encode($doc, JSON_THROW_ON_ERROR));

        // Garante pedido/parcela mínimos para vincular a guia
        if ($order === null) {
            $order = TaxInstallmentOrder::query()->updateOrCreate(
                [
                    'office_id' => $request->office->id,
                    'client_id' => $request->client->id,
                    'modality' => $modality->value,
                    'external_order_id' => $orderExternalId !== '' ? $orderExternalId : 'UNKNOWN',
                ],
                [
                    'regime' => $modality->regime(),
                    'situation' => FiscalSituation::Pending->value,
                    'source_system' => ParcelamentoServiceCatalog::SOLUTION,
                    'source_service' => $modality->value,
                    'source_operation' => 'EMITIR_DOCUMENTO',
                    'observed_at' => CarbonImmutable::now(),
                ],
            );
        }

        if ($parcel === null) {
            $logical = implode(':', ['PARC', $modality->value, $order->external_order_id, $parcelKey]);
            $parcel = TaxInstallmentParcel::query()->updateOrCreate(
                [
                    'office_id' => $request->office->id,
                    'order_id' => $order->id,
                    'parcel_key' => $parcelKey,
                ],
                [
                    'client_id' => $request->client->id,
                    'modality' => $modality->value,
                    'status' => TaxInstallmentParcelStatus::Emitted,
                    'document_available' => true,
                    'logical_key' => $logical,
                    'amount_cents' => isset($doc['valorCentavos']) ? (int) $doc['valorCentavos'] : null,
                    'due_at' => isset($doc['vencimento'])
                        ? CarbonImmutable::parse((string) $doc['vencimento'])
                        : null,
                ],
            );
        }

        $enroll = $this->guides->enrollFromInstallmentDocument(
            $request->office,
            $request->client,
            $parcel,
            [
                'modality' => $modality->value,
                'order_external_id' => $order->external_order_id,
                'parcel_key' => $parcelKey,
                'document_type' => (string) ($doc['tipo'] ?? 'DAS'),
                'identifier' => $doc['identificador'] ?? null,
                'amount_cents' => isset($doc['valorCentavos']) ? (int) $doc['valorCentavos'] : null,
                'due_at' => $doc['vencimento'] ?? null,
                'valid_until' => $doc['validoAte'] ?? null,
                'content_sha256' => $sha,
                'content_type' => (string) ($doc['contentType'] ?? 'application/pdf'),
                'source_system' => ParcelamentoServiceCatalog::SOLUTION,
                'source_service' => $modality->value,
                'source_operation' => 'EMITIR_DOCUMENTO',
                'correlation_id' => $request->run->correlation_id,
                'run_id' => $request->run->id,
            ],
        );

        $guide = $enroll['guide'];
        $parcel->forceFill(['status' => TaxInstallmentParcelStatus::Emitted])->save();

        $evidence = json_encode([
            'reused' => $enroll['reused'],
            'guide_id' => $guide->id,
            'content_sha256' => $sha,
            'modality' => $modality->value,
            'parcel_key' => $parcelKey,
            'payment_status' => $guide->payment_status?->value,
            'simulated' => $isTrial,
        ], JSON_THROW_ON_ERROR);

        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: FiscalSituation::UpToDate,
            coverage: FiscalCoverage::Full,
            evidenceBytes: $evidence,
            sourceVersion: $isTrial ? 'parcelamento-serpro-trial' : 'parcelamento-serpro',
            normalized: [
                'reused' => $enroll['reused'],
                'guide' => $guide->toPublicArray(),
                // Emissão não implica pagamento
                'payment_status' => $guide->payment_status?->value,
                'emission_status' => $guide->currentVersion?->emission_status?->value,
            ],
            findings: [[
                'code' => $enroll['reused'] ? 'GUIA_REUTILIZADA' : 'GUIA_EMITIDA',
                'severity' => 'INFO',
                'title' => $enroll['reused'] ? 'Guia reutilizada' : 'Guia emitida e registrada',
                'detail' => 'Pagamento permanece independente da emissão.',
                'creates_pending' => false,
            ]],
            itemsProcessed: 1,
        );
    }
}
