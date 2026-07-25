<?php

namespace App\Services\Fiscal\Guides;

use App\Contracts\FiscalSourceAdapter;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Services\Serpro\SerproOperationService;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PagtowebPaymentListAdapter implements FiscalSourceAdapter
{
    public const OPERATION_KEY = 'pagtoweb.pagamentos';

    public const SYSTEM = 'PAGTOWEB';

    public const SERVICE = 'PAGTOWEB';

    public const OPERATION = 'CONSULTAR_PAGAMENTOS';

    public function __construct(private readonly SerproOperationService $operations, private readonly PagtowebPaymentListCodec $codec, private readonly PagtowebPaymentListProjector $projector) {}

    public function systemCode(): string
    {
        return self::SYSTEM;
    }

    public function serviceCode(): string
    {
        return self::SERVICE;
    }

    public function operationCode(): string
    {
        return self::OPERATION;
    }

    public function mutability(): FiscalMutability
    {
        return FiscalMutability::ReadOnly;
    }

    public function coverage(): FiscalCoverage
    {
        return FiscalCoverage::Full;
    }

    public function moduleKey(): ?string
    {
        return 'guias';
    }

    public function supports(FiscalAdapterRequest $request): bool
    {
        return strcasecmp($request->systemCode, self::SYSTEM) === 0 && strcasecmp($request->serviceCode, self::SERVICE) === 0 && strcasecmp($request->operationCode, self::OPERATION) === 0;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        if (! FeatureFlags::isModuleEnabled('guias', $request->office->id) && ! (bool) config('fiscal_monitoring.enabled', false)) {
            return FiscalAdapterResult::blocked('Módulo guias desabilitado.', 'FEATURE_DISABLED');
        }
        try {
            $filters = (array) ($request->progress['pagtoweb_payment_list_filters'] ?? []);
            $encryptedDocuments = $request->progress['pagtoweb_payment_list_documents_encrypted'] ?? null;
            if (is_string($encryptedDocuments) && $encryptedDocuments !== '') {
                $filters['numero_documento_lista'] = $this->codec->decryptDocumentNumbers($encryptedDocuments);
            }
            $normalized = $this->codec->normalizeFilters($filters);
            $response = $this->operations->execute(office: $request->office, client: $request->client, operationKey: self::OPERATION_KEY, businessData: $normalized['business_data'], idempotencyKey: 'pagtoweb-payment-list:'.$request->run->idempotency_key, correlationId: $request->run->correlation_id, entityKey: 'fiscal-run:'.$request->run->id, module: 'guias');
            if (! $response->success) {
                return FiscalAdapterResult::failed($response->errorMessage ?? 'Falha na consulta de pagamentos.', $response->errorCode ?? 'PAGTOWEB_PAYMENT_LIST_FAILED', $this->coverage());
            }
            if ($response->hasSimulatedSource()) {
                return FiscalAdapterResult::blocked('Resposta sintética não pode gerar projeção PAGTOWEB.', 'SIMULATED_SOURCE_REJECTED');
            }
            $items = $this->codec->decodePayments($response->dados ?? $response->body);
            $provenance = $response->isProductiveEvidence()
                ? FiscalSourceProvenance::SerproReal->value
                : ($response->sourceProvenance === FiscalSourceProvenance::SerproTrial->value
                    ? FiscalSourceProvenance::SerproTrial->value
                    : FiscalSourceProvenance::Unverified->value);
            $projected = $this->projector->project($request->office, $request->client, $items, $normalized['filter_summary'], $request->run->id, $provenance);
        } catch (Throwable) {
            Log::warning('pagtoweb.payment_list_failed', ['operation_key' => self::OPERATION_KEY, 'office_id' => $request->office->id, 'client_id' => $request->client->id, 'reason' => 'PAYMENT_LIST_FAILED']);

            return FiscalAdapterResult::failed('Não foi possível consultar os pagamentos.', 'PAGTOWEB_PAYMENT_LIST_FAILED', $this->coverage());
        }
        $request->run->forceFill(['source_provenance' => $provenance])->save();
        $public = $projected['observation']->toPublicArray();

        return new FiscalAdapterResult(result: FiscalRunResult::Success, situation: FiscalSituation::Unknown, coverage: $this->coverage(), evidenceBytes: json_encode(['operation_key' => self::OPERATION_KEY, ...$public], JSON_THROW_ON_ERROR), sourceVersion: 'PAGTOWEB-7.1', normalized: ['pagtoweb_payment_list' => ['promoted' => true, ...$public]], itemsProcessed: $public['returned_count']);
    }
}
