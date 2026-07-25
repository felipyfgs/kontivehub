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
use InvalidArgumentException;
use Throwable;

/** Adapter de leitura do serviço SICALC/CONSULTAAPOIORECEITAS52 (5.2). */
final class SicalcRevenueSupportAdapter implements FiscalSourceAdapter
{
    public const OPERATION_KEY = 'sicalc.consultaapoioreceitas';

    public const SYSTEM = 'SICALC';

    public const SERVICE = 'SICALC';

    public const OPERATION = 'CONSULTAR_APOIO_RECEITAS';

    public function __construct(
        private readonly SerproOperationService $operations,
        private readonly SicalcRevenueSupportCodec $codec,
        private readonly SicalcRevenueSupportProjector $projector,
    ) {}

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
        if (! FeatureFlags::isModuleEnabled('guias', $request->office->id)
            && ! (bool) config('fiscal_monitoring.enabled', false)) {
            return FiscalAdapterResult::blocked('Módulo guias desabilitado.', 'FEATURE_DISABLED');
        }
        $code = trim((string) ($request->progress['sicalc_revenue_code'] ?? $request->context['codigo_receita'] ?? ''));
        if (! preg_match('/^[0-9]{1,16}$/', $code)) {
            return FiscalAdapterResult::failed('Código de receita inválido para o SICALC.', 'INVALID_REVENUE_CODE');
        }

        try {
            $response = $this->operations->execute(
                office: $request->office,
                client: $request->client,
                operationKey: self::OPERATION_KEY,
                businessData: ['codigoReceita' => $code],
                idempotencyKey: 'sicalc-support:'.$request->run->idempotency_key,
                correlationId: $request->run->correlation_id,
                entityKey: 'fiscal-run:'.$request->run->id,
                module: 'guias',
            );
        } catch (Throwable) {
            return FiscalAdapterResult::failed('Falha de transporte Integra Contador.', 'TRANSPORT_ERROR');
        }
        if (! $response->success) {
            return FiscalAdapterResult::failed(
                $response->errorMessage ?? 'Falha na consulta de apoio SICALC.',
                $response->errorCode ?? 'SICALC_REVENUE_SUPPORT_FAILED',
                $this->coverage(),
            );
        }

        try {
            $summary = $this->codec->decode($response->dados ?? $response->body['dados'] ?? $response->body);
            if ($summary['revenue_code'] !== $code) {
                throw new InvalidArgumentException('Resposta SICALC não corresponde ao código solicitado.');
            }
            if ($response->hasSimulatedSource()) {
                return FiscalAdapterResult::blocked('Resposta sintética não pode gerar projeção SICALC.', 'SIMULATED_SOURCE_REJECTED');
            }
            $provenance = $response->isProductiveEvidence()
                ? FiscalSourceProvenance::SerproReal->value
                : ($response->sourceProvenance === FiscalSourceProvenance::SerproTrial->value
                    ? FiscalSourceProvenance::SerproTrial->value
                    : FiscalSourceProvenance::Unverified->value);
            $projected = $this->projector->project(
                $request->office, $request->client, $summary, $request->run->id, $provenance,
            );
        } catch (InvalidArgumentException $e) {
            return FiscalAdapterResult::failed($e->getMessage(), 'INVALID_SICALC_RESPONSE', $this->coverage());
        } catch (Throwable) {
            Log::warning('sicalc.revenue_support_projection_failed', [
                'operation_key' => self::OPERATION_KEY,
                'office_id' => $request->office->id,
                'client_id' => $request->client->id,
                'reason' => 'PROJECTION_FAILED',
            ]);

            return FiscalAdapterResult::failed('Não foi possível registrar a consulta de apoio SICALC.', 'PROJECTION_FAILED', $this->coverage());
        }

        $request->run->forceFill(['source_provenance' => $provenance])->save();
        $public = $projected['projection']->toPublicArray();
        $evidence = json_encode([
            'operation_key' => self::OPERATION_KEY,
            'revenue_code' => $public['revenue_code'],
            'description' => $public['description'],
            'extensions' => $public['extensions'],
            'source_provenance' => $public['source_provenance'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: FiscalSituation::Unknown,
            coverage: $this->coverage(),
            evidenceBytes: $evidence,
            sourceVersion: 'SICALC-5.2',
            normalized: ['sicalc_revenue_support' => ['promoted' => true, ...$public]],
            itemsProcessed: (int) $public['extension_count'],
        );
    }
}
