<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\ListFiscalTaxProcessesAction;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\ListFiscalTaxProcessesRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewFiscalMonitoringRequest;
use App\Http\Resources\Fiscal\ClientFiscalTaxProcessesResource;
use App\Http\Resources\Fiscal\FiscalTaxProcessCollection;
use App\Http\Resources\Fiscal\FiscalTaxProcessResource;
use App\Jobs\Fiscal\RefreshTaxProcessesJob;
use App\Models\Client;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * APIs tenant-scoped de Processos fiscais (tenant da sessão).
 */
final class TaxProcessController extends Controller
{
    public function index(
        ListFiscalTaxProcessesRequest $request,
        CurrentTenant $currentTenant,
        ListFiscalTaxProcessesAction $action,
    ): FiscalTaxProcessCollection {
        $tenant = $currentTenant->tenant();
        abort_if($tenant === null, 403);

        return new FiscalTaxProcessCollection(
            $action->handle($tenant, $request->filters()),
        );
    }

    public function showForClient(
        ViewFiscalMonitoringRequest $request,
        int $clientId,
        CurrentTenant $currentTenant,
        ListFiscalTaxProcessesAction $action,
    ): ClientFiscalTaxProcessesResource {
        $tenant = $currentTenant->tenant();
        abort_if($tenant === null, 403);

        return new ClientFiscalTaxProcessesResource(
            $action->forClient($tenant, $clientId),
        );
    }

    public function show(
        ViewFiscalMonitoringRequest $request,
        int $id,
        CurrentTenant $currentTenant,
        ListFiscalTaxProcessesAction $action,
    ): FiscalTaxProcessResource {
        $tenant = $currentTenant->tenant();
        abort_if($tenant === null, 403);

        return new FiscalTaxProcessResource($action->find($tenant, $id));
    }

    public function refresh(int $clientId, CurrentTenant $currentTenant): JsonResponse
    {
        $tenant = $currentTenant->tenant();
        abort_if($tenant === null, 403);
        $role = $currentTenant->role();
        if ($role === null || ! in_array($role, [TenantRole::TenantAdmin, TenantRole::TenantUser], true)) {
            abort(403);
        }

        $client = Client::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($clientId)
            ->firstOrFail();

        $job = RefreshTaxProcessesJob::dispatchIfAllowed(
            (int) $tenant->id,
            (int) $client->id,
            bin2hex(random_bytes(8)),
        );

        if ($job === null) {
            return response()->json([
                'message' => 'Capability tax_processes desabilitada ou kill switch ativo.',
                'data' => ['queued' => false, 'client_id' => $client->id],
            ], 423);
        }

        return response()->json([
            'data' => [
                'queued' => true,
                'client_id' => $client->id,
            ],
        ], 202);
    }
}
