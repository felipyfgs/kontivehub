<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\Fiscal\Monitoring\ListSicalcRevenueSupportHistoryRequest;
use App\Http\Requests\Fiscal\Mutations\ConsultSicalcRevenueSupportRequest;
use App\Http\Resources\Fiscal\FiscalMonitoringDataResource;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Guides\SicalcRevenueSupportQueryService;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/** API tenant-scoped para apoio de receitas SICALC 5.2. */
final class SicalcRevenueSupportController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly SicalcRevenueSupportQueryService $queries,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function history(
        ListSicalcRevenueSupportHistoryRequest $request,
        int $client,
        FindFiscalClientAction $findClient,
    ): JsonResponse|FiscalMonitoringDataResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return $this->clientNotFound();
        }
        $request->ensureCanView($model);
        $filters = $request->filters();
        try {
            return new FiscalMonitoringDataResource(
                $this->queries->history(
                    $tenant,
                    $model,
                    $filters->revenueCode,
                ),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_REVENUE_CODE'], 422);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Histórico de apoio SICALC indisponível.', 'code' => 'HISTORY_ERROR'], 422);
        }
    }

    public function consult(ConsultSicalcRevenueSupportRequest $request, int $client): JsonResponse
    {
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $model = $this->findClient($this->currentTenant->tenant()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanWrite($request, $model);
        try {
            $run = $this->queries->enqueueManualConsult($this->currentTenant->tenant(), $model, $request->revenueCode(), $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_REVENUE_CODE'], 422);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Consulta de apoio SICALC indisponível.', 'code' => 'SICALC_UNAVAILABLE'], 422);
        }

        return response()->json(['data' => $run], 201);
    }

    private function findClient(int $tenantId, int $clientId): ?Client
    {
        return Client::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($clientId)->first();
    }

    private function clientNotFound(): JsonResponse
    {
        return response()->json(['message' => 'Cliente não encontrado no escritório atual.', 'code' => 'CLIENT_NOT_FOUND'], 404);
    }

    private function rejectClientTenantId(Request $request): ?JsonResponse
    {
        $supplied = $request->attributes->get(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED) === true
            || $this->containsTenantIdKey($request->query->all()) || $this->containsTenantIdKey($request->request->all())
            || ($request->isJson() && $request->json() !== null && $this->containsTenantIdKey($request->json()->all()));

        return $supplied ? response()->json([
            'message' => 'tenant_id não é aceito; o escritório é obtido do contexto autenticado.',
            'code' => 'CLIENT_TENANT_ID_REJECTED',
        ], 422) : null;
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

    private function assertCanRead(Request $request, Client $client): void
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, TenantPermission::FiscalMonitoringView, $client)) {
            abort(403, 'Sem permissão para consultar o monitoramento fiscal.');
        }
    }

    private function assertCanWrite(Request $request, Client $client): void
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, TenantPermission::FiscalSyncTrigger, $client)) {
            abort(403, 'Sem permissão de sincronização.');
        }
    }

    private function assertModuleEnabled(): void
    {
        $tenant = $this->currentTenant->tenant();
        if (! FeatureFlags::isModuleEnabled('guides', $tenant->id) && ! (bool) config('fiscal_monitoring.enabled', false)) {
            abort(403, 'Módulo guias desabilitado.');
        }
    }
}
