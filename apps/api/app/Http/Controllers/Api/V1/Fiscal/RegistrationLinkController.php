<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\ListFiscalRegistrationLinksAction;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\ListFiscalRegistrationLinksRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewFiscalMonitoringRequest;
use App\Http\Resources\Fiscal\ClientFiscalRegistrationLinksResource;
use App\Http\Resources\Fiscal\FiscalRegistrationLinkCollection;
use App\Jobs\Fiscal\RefreshRegistrationLinksJob;
use App\Models\Client;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * APIs tenant-scoped de Cadastro/Vínculos (tenant da sessão).
 */
final class RegistrationLinkController extends Controller
{
    public function index(
        ListFiscalRegistrationLinksRequest $request,
        CurrentTenant $currentTenant,
        ListFiscalRegistrationLinksAction $action,
    ): FiscalRegistrationLinkCollection {
        $tenant = $currentTenant->tenant();
        abort_if($tenant === null, 403);

        return new FiscalRegistrationLinkCollection(
            $action->handle($tenant, $request->filters()),
        );
    }

    public function showForClient(
        ViewFiscalMonitoringRequest $request,
        int $clientId,
        CurrentTenant $currentTenant,
        ListFiscalRegistrationLinksAction $action,
    ): ClientFiscalRegistrationLinksResource {
        $tenant = $currentTenant->tenant();
        abort_if($tenant === null, 403);

        return new ClientFiscalRegistrationLinksResource(
            $action->forClient($tenant, $clientId),
        );
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

        $job = RefreshRegistrationLinksJob::dispatchIfAllowed(
            (int) $tenant->id,
            (int) $client->id,
            bin2hex(random_bytes(8)),
        );

        if ($job === null) {
            return response()->json([
                'message' => 'Capability registrations desabilitada ou kill switch ativo.',
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
