<?php

namespace App\Services\Fiscal\Guides;

use App\Contracts\FiscalSourceAdapter;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Services\Serpro\SerproOperationService;
use App\Support\FeatureFlags;
use Throwable;

/** Adapter do 7.2: número só existe no contexto da chamada manual em memória. */
final class PagtowebArrecadacaoReceiptAdapter implements FiscalSourceAdapter
{
    public const OPERATION_KEY = 'pagtoweb.comparrecadacao';

    public const SYSTEM = 'PAGTOWEB';

    public const SERVICE = 'COMPARRECADACAO72';

    public const OPERATION = 'EMITIR_COMPROVANTE_ARRECADACAO';

    public function __construct(private readonly SerproOperationService $operations, private readonly PagtowebArrecadacaoReceiptCodec $codec) {}

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
        return strcasecmp($request->systemCode, self::SYSTEM) === 0
            && strcasecmp($request->serviceCode, self::SERVICE) === 0
            && strcasecmp($request->operationCode, self::OPERATION) === 0;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        if (! FeatureFlags::isModuleEnabled('guias', $request->office->id) && ! (bool) config('fiscal_monitoring.enabled', false)) {
            return FiscalAdapterResult::blocked('Módulo guias desabilitado.', 'FEATURE_DISABLED');
        }
        try {
            $businessData = $this->codec->normalizeRequest($request->context['numeroDocumento'] ?? null);
            $response = $this->operations->execute(
                office: $request->office, client: $request->client, operationKey: self::OPERATION_KEY,
                businessData: $businessData, idempotencyKey: 'pagtoweb-receipt:'.$request->run->idempotency_key,
                correlationId: $request->run->correlation_id, entityKey: 'fiscal-run:'.$request->run->id, module: 'guias',
            );
        } catch (Throwable) {
            return FiscalAdapterResult::failed('Não foi possível solicitar o comprovante.', 'PAGTOWEB_RECEIPT_FAILED', $this->coverage());
        }
        if (! $response->success) {
            return FiscalAdapterResult::failed($response->errorMessage ?? 'Não foi possível solicitar o comprovante.', $response->errorCode ?? 'PAGTOWEB_RECEIPT_FAILED', $this->coverage());
        }
        if ($response->hasSimulatedSource() || ! is_array($response->dados) || ! isset($response->dados['receipt_id'])) {
            return FiscalAdapterResult::failed('Comprovante sem descritor seguro.', 'DOCUMENT_SECURE_CAPTURE_FAILED', $this->coverage());
        }

        $public = $response->dados;
        $request->run->forceFill(['source_provenance' => $response->sourceProvenance])->save();

        return new FiscalAdapterResult(
            result: FiscalRunResult::Success, situation: FiscalSituation::Unknown, coverage: $this->coverage(),
            evidenceBytes: json_encode(['operation_key' => self::OPERATION_KEY, 'receipt_id' => $public['receipt_id']], JSON_THROW_ON_ERROR),
            sourceVersion: 'PAGTOWEB-7.2', normalized: ['pagtoweb_receipt' => $public], itemsProcessed: 1,
        );
    }
}
