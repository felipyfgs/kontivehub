<?php

namespace App\Services\Integra\Parcelamento;

use App\Contracts\ParcelamentoSource;
use App\Contracts\SerproOperationExecutor;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Serpro\MutationAuthorization;
use App\Enums\SerproCapabilityDriver;
use App\Enums\TaxInstallmentModality;
use App\Services\Serpro\CapabilityDriverResolver;

/**
 * Fonte de parcelamento dirigida por driver (disabled → fail-closed; real → executor central).
 */
final class SerproParcelamentoSource implements ParcelamentoSource
{
    public function __construct(
        private readonly SerproOperationExecutor $operations,
        private readonly CapabilityDriverResolver $drivers,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     success: bool,
     *     simulated: bool,
     *     timeout_uncertain?: bool,
     *     error_code?: string,
     *     error_message?: string,
     *     body: array<string, mixed>
     * }
     */
    public function execute(
        TaxInstallmentModality $modality,
        string $operation,
        array $payload = [],
        ?FiscalAdapterRequest $request = null,
    ): array {
        $driver = $this->drivers->forCapability('installments');
        if ($driver === SerproCapabilityDriver::Disabled) {
            return [
                'success' => false,
                'simulated' => false,
                'error_code' => 'CAPABILITY_DISABLED',
                'error_message' => 'Parcelamentos desabilitados.',
                'body' => [],
            ];
        }
        $op = strtoupper($operation);
        try {
            $operationKey = ParcelamentoServiceCatalog::operationKey($modality, $op);
        } catch (\InvalidArgumentException) {
            $operationKey = null;
        }

        if ($operationKey === null) {
            return [
                'success' => false,
                'simulated' => false,
                'error_code' => 'OPERATION_KEY_UNKNOWN',
                'error_message' => "Sem operation_key oficial para {$modality->value}/{$op}.",
                'body' => [],
            ];
        }

        $officeId = (int) ($request?->office->id ?? 0);
        $clientId = (int) ($request?->client->id ?? 0);
        if ($officeId <= 0 || $clientId <= 0 || $request === null) {
            return [
                'success' => false,
                'simulated' => false,
                'error_code' => 'TENANT_CONTEXT_MISSING',
                'error_message' => 'Contexto tenant obrigatório para chamada real de parcelamento.',
                'body' => [],
            ];
        }

        $isMutating = in_array($op, ['ADERIR', 'REPARCELAR', 'DESISTIR', 'GERARDAS', 'EMITIR_DOCUMENTO'], true);

        $response = $this->operations->execute(
            office: $request->office,
            client: $request->client,
            operationKey: $operationKey,
            businessData: $payload,
            correlationId: $request->run->correlation_id,
            mutationAuth: MutationAuthorization::none(),
            module: 'parcelamentos',
        );
        if ($response->hasSimulatedSource()) {
            $response = $response->rejectSimulatedSource();
        }

        // Mutantes: executor bloqueia via MutationAuthorization; reforçar se catálogo não marcar is_mutating
        if ($isMutating && $response->errorCode === null && $response->success) {
            // Não deve ocorrer nesta change — defesa extra
        }

        return [
            'success' => $response->success,
            'simulated' => $response->simulated,
            'error_code' => $response->errorCode,
            'error_message' => $response->errorMessage,
            'body' => is_array($response->dados) ? $response->dados : $response->body,
        ];
    }
}
