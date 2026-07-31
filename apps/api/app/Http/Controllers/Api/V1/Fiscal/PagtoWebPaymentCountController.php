<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\Fiscal\Monitoring\ViewFiscalClientHistoryRequest;
use App\Http\Requests\Fiscal\Mutations\ConsultPagtoWebPaymentFiltersRequest;
use App\Http\Resources\Fiscal\FiscalMonitoringDataResource;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Guides\PagtoWebPaymentCountQueryService;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

final class PagtoWebPaymentCountController extends Controller
{
    public function __construct(private readonly CurrentTenant $currentTenant, private readonly PagtoWebPaymentCountQueryService $queries, private readonly TenantAuthorization $authorization) {}

    public function history(
        ViewFiscalClientHistoryRequest $request,
        int $client,
        FindFiscalClientAction $findClient,
    ): JsonResponse|FiscalMonitoringDataResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return $this->notFound();
        }
        $request->ensureCanView($model);
        try {
            return new FiscalMonitoringDataResource(
                $this->queries->history($tenant, $model),
            );
        } catch (RuntimeException) {
            return response()->json(['message' => 'Histórico de contagem indisponível.', 'code' => 'HISTORY_ERROR'], 422);
        }
    }

    public function consult(ConsultPagtoWebPaymentFiltersRequest $request, int $client): JsonResponse
    {
        $this->enabled();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $model = $this->client($client);
        if ($model === null) {
            return $this->notFound();
        }
        $this->write($request, $model);
        try {
            $run = $this->queries->enqueueManualConsult($this->currentTenant->tenant(), $model, $request->filters(), $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_PAYMENT_COUNT_FILTERS'], 422);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Contagem de pagamentos indisponível.', 'code' => 'PAGTOWEB_UNAVAILABLE'], 422);
        }

        return response()->json(['data' => $run], 201);
    }

    private function client(int $id): ?Client
    {
        return Client::query()->withoutGlobalScopes()->where('tenant_id', $this->currentTenant->tenant()->id)->whereKey($id)->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Cliente não encontrado no escritório atual.', 'code' => 'CLIENT_NOT_FOUND'], 404);
    }

    private function read(Request $request, Client $client): void
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, TenantPermission::FiscalMonitoringView, $client)) {
            abort(403, 'Sem permissão para consultar o monitoramento fiscal.');
        }
    }

    private function write(Request $request, Client $client): void
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, TenantPermission::FiscalSyncTrigger, $client)) {
            abort(403, 'Sem permissão de sincronização.');
        }
    }

    private function enabled(): void
    {
        $tenant = $this->currentTenant->tenant();
        if (! FeatureFlags::isModuleEnabled('guides', $tenant->id) && ! (bool) config('fiscal_monitoring.enabled', false)) {
            abort(403, 'Módulo guias desabilitado.');
        }
    }

    private function rejectClientTenantId(Request $request): ?JsonResponse
    {
        $supplied = $request->attributes->get(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED) === true || $this->hasTenantId($request->query->all()) || $this->hasTenantId($request->request->all()) || ($request->isJson() && $request->json() !== null && $this->hasTenantId($request->json()->all()));

        return $supplied ? response()->json(['message' => 'tenant_id não é aceito; o escritório é obtido do contexto autenticado.', 'code' => 'CLIENT_TENANT_ID_REJECTED'], 422) : null;
    }

    /** @param array<array-key,mixed> $values */
    private function hasTenantId(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && strtolower($key) === 'tenant_id') {
                return true;
            } if (is_array($value) && $this->hasTenantId($value)) {
                return true;
            }
        }

        return false;
    }
}
