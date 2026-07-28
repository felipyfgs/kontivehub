<?php

namespace App\Actions\Fiscal\Mutations;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\DTO\Fiscal\Mutations\EnqueueFiscalMonitoringRunData;
use App\Models\FiscalMonitoringRun;
use App\Models\User;
use App\Services\Fiscal\SimplesMei\SimplesMeiCatalog;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Parcelamento\ParcelamentoServiceCatalog;
use App\Support\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use RuntimeException;

final readonly class EnqueueFiscalMonitoringRunAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private FindFiscalClientAction $findClient,
        private FiscalMonitoringRunService $runs,
    ) {}

    public function handle(User $actor, EnqueueFiscalMonitoringRunData $data): FiscalMonitoringRun
    {
        $tenant = $this->currentTenant->tenant();

        // Enqueue genérico é read-only: mutações usam endpoints dedicados com senha recente.
        if ($this->isMutativeOperationCode(
            $data->systemCode,
            $data->serviceCode,
            $data->operationCode,
        )) {
            throw new HttpResponseException(response()->json([
                'message' => 'Operações mutantes não podem ser enfileiradas pelo endpoint genérico de runs. Use o endpoint dedicado.',
                'code' => 'MUTATING_ENQUEUE_FORBIDDEN',
            ], 403));
        }

        $client = $this->findClient->handle($tenant, $data->clientId);
        if ($client === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'Cliente não encontrado.',
            ], 404));
        }

        try {
            return $this->runs->enqueueManual(
                tenant: $tenant,
                client: $client,
                systemCode: $data->systemCode,
                serviceCode: $data->serviceCode,
                operationCode: $data->operationCode,
                actorId: $actor->id,
                correlationId: $data->correlationId,
                dispatch: true,
            );
        } catch (RuntimeException $e) {
            throw new HttpResponseException(response()->json([
                'message' => $e->getMessage(),
            ], 422));
        }
    }

    private function isMutativeOperationCode(
        string $systemCode,
        string $serviceCode,
        string $operationCode,
    ): bool {
        if (in_array($operationCode, DctfwebCodes::mutatingOperations(), true)) {
            return true;
        }

        if (ParcelamentoServiceCatalog::isMutatingOperation($operationCode)) {
            return true;
        }

        $simples = SimplesMeiCatalog::find($systemCode, $serviceCode, $operationCode);
        if ($simples !== null && $simples->mutability->isMutating()) {
            return true;
        }

        return false;
    }
}
