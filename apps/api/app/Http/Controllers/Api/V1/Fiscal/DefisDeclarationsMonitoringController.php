<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\Fiscal\Monitoring\ViewDefisDeclarationsHistoryRequest;
use App\Http\Requests\Fiscal\Mutations\ConfirmFiscalOperationRequest;
use App\Http\Resources\Fiscal\FiscalMonitoringDataResource;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\SimplesMei\DefisDeclarationsMonitoringQueryService;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DefisDeclarationsMonitoringController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly DefisDeclarationsMonitoringQueryService $queries,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function history(
        ViewDefisDeclarationsHistoryRequest $request,
        int $client,
        FindFiscalClientAction $findClient,
    ): JsonResponse|FiscalMonitoringDataResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return $this->clientNotFound();
        }
        $request->ensureCanView($model);

        try {
            return new FiscalMonitoringDataResource(
                $this->queries->history($tenant, $model),
            );
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'CLIENT_NOT_FOUND'], $e->getStatusCode());
        } catch (RuntimeException) {
            return response()->json(['message' => 'Histórico DEFIS indisponível.', 'code' => 'HISTORY_ERROR'], 422);
        }
    }

    public function consult(ConfirmFiscalOperationRequest $request, int $client): JsonResponse
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
            $run = $this->queries->enqueueManualConsult($this->currentTenant->tenant(), $model, $request->user()?->id);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'CLIENT_NOT_FOUND'], $e->getStatusCode());
        } catch (RuntimeException) {
            return response()->json(['message' => 'Consulta DEFIS indisponível.', 'code' => 'DEFIS_UNAVAILABLE'], 422);
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
        $suppliedAtTopLevel = $request->attributes->get(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED) === true;
        $suppliedNested = $this->containsTenantIdKey($request->query->all())
            || $this->containsTenantIdKey($request->request->all())
            || ($request->isJson() && $request->json() !== null && $this->containsTenantIdKey($request->json()->all()));
        if (! $suppliedAtTopLevel && ! $suppliedNested) {
            return null;
        }

        return response()->json([
            'message' => 'tenant_id não é aceito; o escritório é obtido do contexto autenticado.',
            'code' => 'CLIENT_TENANT_ID_REJECTED',
        ], 422);
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
        if (! FeatureFlags::isModuleEnabled('simples_mei', $tenant->id)
            && ! (bool) config('fiscal_monitoring.enabled', false)) {
            abort(403, 'Módulo simples_mei desabilitado.');
        }
    }
}
