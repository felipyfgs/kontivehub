<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Sitfis\SitfisHistoryQueryService;
use App\Services\Integra\Sitfis\SitfisSnapshotService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Situação Fiscal (SITFIS): leitura com idade do snapshot; refresh respeita TTL.
 */
class SitfisSituationController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly SitfisSnapshotService $sitfis,
        private readonly SitfisHistoryQueryService $historyQueries,
        private readonly TenantAuthorization $authorization,
    ) {}

    /**
     * Histórico local consolidado por consulta. Nunca dispara refresh/Integra.
     */
    public function history(Request $request, int $client): JsonResponse
    {
        $this->assertCanRead();
        if ($this->clientTenantIdWasSupplied($request)) {
            return response()->json([
                'message' => 'tenant_id não é aceito; o escritório é obtido do contexto autenticado.',
                'code' => 'CLIENT_TENANT_ID_REJECTED',
            ], 422);
        }

        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient((int) $tenant->id, $client);
        if ($model === null) {
            return response()->json([
                'message' => 'Cliente não encontrado no escritório atual.',
                'code' => 'CLIENT_NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'data' => $this->historyQueries->history($tenant, $model),
        ]);
    }

    /**
     * GET — devolve snapshot existente + idade. Nunca dispara nova chamada só por abrir a tela.
     */
    public function show(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $tenant = $this->currentTenant->tenant();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
        ]);

        $client = $this->findClient($tenant->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $view = $this->sitfis->current($tenant, $client);

        return response()->json([
            'data' => $this->sitfis->publicView($view),
        ]);
    }

    /**
     * POST — solicita nova emissão só se TTL expirado ou ausente.
     */
    public function refresh(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $tenant = $this->currentTenant->tenant();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $client = $this->findClient($tenant->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $result = $this->sitfis->refresh(
                tenant: $tenant,
                client: $client,
                force: (bool) ($data['force'] ?? false),
                actorId: $request->user()?->id,
                dispatch: true,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $status = $result['enqueued'] ? 202 : 200;

        return response()->json([
            'data' => [
                'enqueued' => $result['enqueued'],
                'reused_snapshot' => $result['reused_snapshot'],
                'reason' => $result['reason'],
                'run' => $result['run']?->toPublicArray(),
                'situation' => $result['view'],
            ],
        ], $status);
    }

    private function findClient(int $tenantId, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($clientId)
            ->first();
    }

    private function assertCanRead(): void
    {
        $actor = request()->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalMonitoringView)) {
            abort(403, 'Perfil não resolvido.');
        }
    }

    private function clientTenantIdWasSupplied(Request $request): bool
    {
        return $request->attributes->get(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED) === true
            || $this->containsTenantIdKey($request->query->all());
    }

    /** @param array<array-key, mixed> $values */
    private function containsTenantIdKey(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && strtolower($key) === 'tenant_id') {
                return true;
            }
            if (is_array($value) && $this->containsTenantIdKey($value)) {
                return true;
            }
        }

        return false;
    }

    private function assertCanWrite(): void
    {
        $role = $this->currentTenant->role();
        if ($role === null || ! in_array($role, [TenantRole::TenantAdmin, TenantRole::TenantUser], true)) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }
}
