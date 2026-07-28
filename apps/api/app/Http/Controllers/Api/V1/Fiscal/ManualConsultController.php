<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\Mutations\ExecuteManualConsultAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\ListManualConsultsRequest;
use App\Http\Requests\Fiscal\Mutations\ExecuteManualConsultRequest;
use App\Services\Fiscal\ManualConsult\ManualConsultInventoryService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * Explorador de consultas manuais somente-leitura (inventário GET + execução POST).
 */
final class ManualConsultController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly ManualConsultInventoryService $inventory,
        private readonly ExecuteManualConsultAction $execute,
    ) {}

    /**
     * GET — inventário local; nunca dispara SERPRO.
     */
    public function index(ListManualConsultsRequest $request): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();
        $client = $request->client();
        if ($filters->clientId !== null && $client === null) {
            return response()->json([
                'message' => 'Cliente não encontrado no escritório atual.',
                'code' => 'CLIENT_NOT_FOUND',
            ], 404);
        }

        $data = $this->inventory->inventory(
            tenant: $tenant,
            client: $client,
            surfaceKey: $filters->surfaceKey,
            moduleKey: $filters->moduleKey,
            actor: $request->actor(),
        );

        return response()->json(['data' => $data]);
    }

    /**
     * POST — consulta confirmada; despacha adapter existente.
     */
    public function store(ExecuteManualConsultRequest $request): JsonResponse
    {
        $result = $this->execute->handle(
            $request->actor(),
            $request->executeData(),
        );

        return response()->json(['data' => $result['payload']], $result['status']);
    }
}
