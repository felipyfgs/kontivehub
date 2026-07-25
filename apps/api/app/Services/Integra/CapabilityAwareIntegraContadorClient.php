<?php

namespace App\Services\Integra;

use App\Contracts\IntegraContadorClient;
use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalSourceProvenance;
use App\Enums\SerproCapabilityDriver;
use App\Services\Serpro\CapabilityDriverResolver;
use InvalidArgumentException;

/**
 * Seleciona o transporte por capacidade, sem fallback entre drivers.
 * operation_key é obrigatório; somente disabled ou real são executáveis.
 */
final class CapabilityAwareIntegraContadorClient implements IntegraContadorClient
{
    public function __construct(
        private readonly CapabilityDriverResolver $drivers,
        private readonly FixtureIntegraContadorClient $fixture,
        private readonly HttpIntegraContadorClient $real,
    ) {}

    public function execute(IntegraRequest $request): IntegraResponse
    {
        if (trim($request->operationKey) === '') {
            throw new InvalidArgumentException('operation_key é obrigatório no gateway Integra.');
        }

        return match ($this->drivers->forOperationKey($request->operationKey)) {
            SerproCapabilityDriver::Disabled => new IntegraResponse(
                success: false,
                httpStatus: 503,
                body: [],
                errorCode: 'CAPABILITY_DISABLED',
                errorMessage: 'Capacidade SERPRO desabilitada.',
                correlationId: $request->correlationId,
                operationKey: $request->operationKey,
                requestTag: $request->resolvedRequestTag(),
                sourceProvenance: FiscalSourceProvenance::Unverified->value,
            ),
            SerproCapabilityDriver::Fixture => $this->fixture->execute($request),
            SerproCapabilityDriver::Real => $this->real->execute($request),
        };
    }
}
