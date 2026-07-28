<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\ListPnrRenunciationsAction;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\Fiscal\Monitoring\ListPnrRenunciationsRequest;
use App\Http\Requests\Fiscal\Mutations\PnrHistoryRequest;
use App\Http\Requests\Fiscal\Mutations\PnrReceiptRequest;
use App\Http\Requests\Fiscal\Mutations\PnrStatusRequest;
use App\Http\Resources\Fiscal\ClientPnrRenunciationsResource;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Integra\Registrations\PnrRenunciationReadService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** APIs de leitura manual PNR; não inclui solicitação de renúncia. */
final class PnrRenunciationController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function index(
        ListPnrRenunciationsRequest $request,
        int $clientId,
        ListPnrRenunciationsAction $action,
    ): ClientPnrRenunciationsResource {
        $tenant = $this->currentTenant->tenant();
        abort_if($tenant === null, 403);

        return new ClientPnrRenunciationsResource(
            $action->handle($tenant, $request->clientId()),
        );
    }

    public function history(PnrHistoryRequest $request, int $clientId, PnrRenunciationReadService $service): JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }

        $tenant = $this->tenant();
        $client = $this->client($tenant->id, $clientId);
        $this->assertCanWrite($request, $client);
        $result = $service->history(
            $tenant,
            $client,
            $request->filters(),
            bin2hex(random_bytes(8)),
        );

        return response()->json(['data' => $result], ($result['success'] ?? false) ? 202 : 422);
    }

    public function status(PnrStatusRequest $request, int $clientId, PnrRenunciationReadService $service): JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }

        $tenant = $this->tenant();
        $client = $this->client($tenant->id, $clientId);
        $this->assertCanWrite($request, $client);
        $result = $service->status(
            $tenant,
            $client,
            $request->solicitationId(),
            bin2hex(random_bytes(8)),
        );

        return response()->json(['data' => $result], ($result['success'] ?? false) ? 202 : 422);
    }

    public function receipt(PnrReceiptRequest $request, int $clientId, PnrRenunciationReadService $service): JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }

        $tenant = $this->tenant();
        $client = $this->client($tenant->id, $clientId);
        $this->assertCanWrite($request, $client);
        $result = $service->receipt(
            $tenant,
            $client,
            $request->renunciationId(),
            bin2hex(random_bytes(8)),
        );

        return response()->json(['data' => $result], ($result['success'] ?? false) ? 202 : 422);
    }

    private function tenant(): Tenant
    {
        $tenant = $this->currentTenant->tenant();
        abort_if($tenant === null, 403);

        return $tenant;
    }

    private function assertCanWrite(Request $request, Client $client): void
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalSyncTrigger, $client)) {
            abort(403, 'Sem permissão de sincronização.');
        }
    }

    private function rejectClientTenantId(Request $request): ?JsonResponse
    {
        $topLevel = $request->attributes->get(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED) === true;
        $nested = $this->containsTenantId($request->query->all())
            || $this->containsTenantId($request->request->all())
            || ($request->isJson() && $request->json() !== null && $this->containsTenantId($request->json()->all()));
        if (! $topLevel && ! $nested) {
            return null;
        }

        return response()->json([
            'message' => 'tenant_id não é aceito; o escritório é obtido do contexto autenticado.',
            'code' => 'CLIENT_TENANT_ID_REJECTED',
        ], 422);
    }

    /** @param array<mixed> $payload */
    private function containsTenantId(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && strcasecmp($key, 'tenant_id') === 0) {
                return true;
            }
            if (is_array($value) && $this->containsTenantId($value)) {
                return true;
            }
        }

        return false;
    }

    private function client(int $tenantId, int $clientId): Client
    {
        return Client::query()->where('tenant_id', $tenantId)->whereKey($clientId)->firstOrFail();
    }
}
