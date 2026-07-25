<?php

namespace App\Services\Integra\Parcelamento;

use App\Contracts\FiscalSourceAdapter;
use App\Contracts\ParcelamentoSource;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Support\FeatureFlags;

/**
 * Adesão / reparcelamento / desistência — sempre atrás de flag mutante OFF no piloto.
 * Nunca chama endpoint mutante real enquanto desabilitado.
 */
final class ParcelamentoMutatingAdapter implements FiscalSourceAdapter
{
    /** Contador de tentativas de acesso remoto (deve permanecer 0 com flags off). */
    public static int $remoteCalls = 0;

    public function __construct(
        private readonly ParcelamentoSource $source,
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
        return '*';
    }

    public function mutability(): FiscalMutability
    {
        return FiscalMutability::Mutating;
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
            && ParcelamentoServiceCatalog::isMutatingOperation($request->operationCode);
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        $op = strtoupper($request->operationCode);
        $modality = $request->serviceCode;

        // Gate 1: núcleo fiscal
        if (! (bool) config('fiscal_monitoring.mutating_enabled', false)) {
            return $this->disabled($op, $modality, 'MUTATING_DISABLED', 'Operações mutantes desabilitadas no núcleo fiscal.');
        }

        // Gate 2: feature flags do módulo
        if (! FeatureFlags::isMutatingEnabled(ParcelamentoServiceCatalog::MODULE_KEY, $request->office->id)) {
            return $this->disabled($op, $modality, 'MUTATING_DISABLED', 'Adesão/reparcelamento/desistência não habilitados no piloto.');
        }

        // Só chega aqui com flags ON — ainda assim fake não executa mutação real
        self::$remoteCalls++;
        $modalityEnum = ParcelamentoServiceCatalog::parseModality($modality);
        if ($modalityEnum === null) {
            return FiscalAdapterResult::unsupported("Modalidade inválida: {$modality}");
        }

        $response = $this->source->execute($modalityEnum, $op, $request->context['payload'] ?? [], $request);

        return FiscalAdapterResult::failed(
            $response['error_message'] ?? 'Mutação de parcelamento não implementada no piloto.',
            $response['error_code'] ?? 'MUTATING_NOT_IMPLEMENTED',
        );
    }

    private function disabled(string $op, string $modality, string $code, string $message): FiscalAdapterResult
    {
        // NÃO chama source — remoteCalls permanece inalterado
        $evidence = json_encode([
            'disabled' => true,
            'operation' => $op,
            'modality' => $modality,
            'remote_called' => false,
        ], JSON_THROW_ON_ERROR);

        return new FiscalAdapterResult(
            result: FiscalRunResult::Blocked,
            situation: FiscalSituation::Blocked,
            coverage: FiscalCoverage::Full,
            evidenceBytes: $evidence,
            normalized: [
                'operation' => $op,
                'modality' => $modality,
                'enabled' => false,
                'remote_called' => false,
            ],
            findings: [[
                'code' => $code,
                'severity' => 'HIGH',
                'title' => 'Operação mutante não habilitada',
                'detail' => $message,
                'situation' => FiscalSituation::Blocked->value,
                'creates_pending' => false,
            ]],
            skipReason: $code,
            errorCode: $code,
            errorMessage: $message,
        );
    }
}
