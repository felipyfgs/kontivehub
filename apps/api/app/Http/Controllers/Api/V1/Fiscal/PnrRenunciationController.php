<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Models\Client;
use App\Models\FiscalPnrRenunciation;
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

    public function index(Request $request, int $clientId): JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }

        $tenant = $this->currentTenant->tenant();
        abort_if($tenant === null, 403);
        $client = $this->client($tenant->id, $clientId);
        $this->assertCanRead($request, $client);

        $rows = FiscalPnrRenunciation::query()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->latest('refreshed_at')
            ->latest('id')
            ->get();

        return response()->json(['data' => [
            'client_id' => $client->id,
            'renunciations' => $rows->map(static fn (FiscalPnrRenunciation $row) => $row->toPublicArray())->values(),
        ]]);
    }

    public function history(Request $request, int $clientId, PnrRenunciationReadService $service): JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }

        $tenant = $this->tenant();
        $client = $this->client($tenant->id, $clientId);
        $this->assertCanWrite($request, $client);
        $result = $service->history($tenant, $client, $request->validate([
            'dt_inicio' => ['nullable', 'date_format:Y-m-d'],
            'dt_fim' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:0'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]), bin2hex(random_bytes(8)));

        return response()->json(['data' => $result], ($result['success'] ?? false) ? 202 : 422);
    }

    public function status(Request $request, int $clientId, PnrRenunciationReadService $service): JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }

        $tenant = $this->tenant();
        $client = $this->client($tenant->id, $clientId);
        $this->assertCanWrite($request, $client);
        $data = $request->validate(['id_solicitacao' => ['required', 'string', 'max:120']]);
        $result = $service->status($tenant, $client, $data['id_solicitacao'], bin2hex(random_bytes(8)));

        return response()->json(['data' => $result], ($result['success'] ?? false) ? 202 : 422);
    }

    public function receipt(Request $request, int $clientId, PnrRenunciationReadService $service): JsonResponse
    {
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }

        $tenant = $this->tenant();
        $client = $this->client($tenant->id, $clientId);
        $this->assertCanWrite($request, $client);
        $data = $request->validate(['renunciation_id' => ['required', 'integer', 'min:1']]);
        $result = $service->receipt($tenant, $client, (int) $data['renunciation_id'], bin2hex(random_bytes(8)));

        return response()->json(['data' => $result], ($result['success'] ?? false) ? 202 : 422);
    }

    private function tenant(): Tenant
    {
        $tenant = $this->currentTenant->tenant();
        abort_if($tenant === null, 403);

        return $tenant;
    }

    private function assertCanRead(Request $request, Client $client): void
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalMonitoringView, $client)) {
            abort(403, 'Sem permissão para consultar o monitoramento fiscal.');
        }
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
