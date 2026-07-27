<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Models\Client;
use App\Services\Fiscal\SimplesMei\RegimeApplicabilityService;
use App\Services\Fiscal\SimplesMei\SimplesMeiCatalog;
use App\Services\Fiscal\SimplesMei\SimplesMeiQueryService;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SimplesMeiController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly SimplesMeiQueryService $queries,
        private readonly RegimeApplicabilityService $regimes,
    ) {}

    public function catalog(): JsonResponse
    {
        $this->assertCanRead();

        return response()->json([
            'data' => SimplesMeiCatalog::toPublicCatalog(),
            'module' => SimplesMeiCatalog::MODULE,
            'module_enabled' => FeatureFlags::isModuleEnabled(
                SimplesMeiCatalog::MODULE,
                $this->currentTenant->tenant()->id,
            ),
            'mutating_enabled' => FeatureFlags::isMutatingEnabled(
                SimplesMeiCatalog::MODULE,
                $this->currentTenant->tenant()->id,
            ),
        ]);
    }

    public function regimes(int $client): JsonResponse
    {
        $this->assertCanRead();
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient($tenant->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $periods = $this->regimes->listPeriods($tenant, $model);

        return response()->json([
            'data' => $periods->map->toPublicArray()->values(),
            'current_tax_regime' => $model->tax_regime,
        ]);
    }

    public function competences(int $client, Request $request): JsonResponse
    {
        $this->assertCanRead();
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient($tenant->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $family = $request->query('regime_family');
        $items = $this->queries->listCompetences(
            $tenant,
            $model,
            is_string($family) ? $family : null,
        );

        return response()->json([
            'data' => $items->map->toPublicArray()->values(),
        ]);
    }

    public function snapshots(int $client, Request $request): JsonResponse
    {
        $this->assertCanRead();
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient($tenant->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $system = $request->query('system_code');
        $page = $this->queries->listSnapshots(
            $tenant,
            $model,
            $perPage,
            is_string($system) ? $system : null,
        );

        $page->getCollection()->transform(fn ($s) => $s->toPublicArray());

        return response()->json($page);
    }

    /**
     * Agenda apenas CONSULTARANOSCALENDARIOS102; GET continua sempre local.
     */
    public function consultRegimeCalendar(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $client = $this->findClient((int) $tenant->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $run = $this->queries->enqueueConsult(
                tenant: $tenant,
                client: $client,
                systemCode: 'INTEGRA_SN',
                serviceCode: 'REGIME_APURACAO',
                operationCode: 'CONSULTAR_ANOS_CALENDARIOS',
                actorId: $request->user()?->id,
                correlationId: $data['correlation_id'] ?? null,
                dispatch: true,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ERROR'], 422);
        }

        return response()->json([
            'data' => $run->toPublicArray(),
            'serpro_call' => 'QUEUED',
        ], 201);
    }

    /** Lista somente a projeção local produzida por CONSULTARANOSCALENDARIOS102. */
    public function regimeCalendar(int $client, Request $request): JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient((int) $tenant->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json([
            'data' => $this->regimes->listCalendarOptions($tenant, $model),
            'provenance' => [
                'source' => 'LOCAL_PROJECTION',
                'serpro_called' => false,
            ],
        ]);
    }

    /** Agenda CONSULTAROPCAOREGIME103 para um ano-calendário explícito. */
    public function consultRegimeOption(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $client = $this->findClient((int) $tenant->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $run = $this->queries->enqueueConsult(
                tenant: $tenant,
                client: $client,
                systemCode: 'INTEGRA_SN',
                serviceCode: 'REGIME_APURACAO',
                operationCode: 'CONSULTAR',
                periodKey: (string) $data['year'],
                actorId: $request->user()?->id,
                correlationId: $data['correlation_id'] ?? null,
                dispatch: true,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ERROR'], 422);
        }

        return response()->json(['data' => $run->toPublicArray(), 'serpro_call' => 'QUEUED'], 201);
    }

    /** Lista somente a projeção local produzida pelo serviço 103. */
    public function regimeOptions(int $client, Request $request): JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient((int) $tenant->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json([
            'data' => $this->regimes->listRegimeOptions($tenant, $model),
            'provenance' => ['source' => 'LOCAL_PROJECTION', 'serpro_called' => false],
        ]);
    }

    /** Agenda CONSULTARRESOLUCAO104; a leitura da resolução permanece local. */
    public function consultRegimeResolution(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $client = $this->findClient((int) $tenant->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $run = $this->queries->enqueueConsult(
                tenant: $tenant,
                client: $client,
                systemCode: 'INTEGRA_SN',
                serviceCode: 'REGIME_APURACAO',
                operationCode: 'CONSULTAR_RESOLUCAO',
                periodKey: (string) $data['year'],
                actorId: $request->user()?->id,
                correlationId: $data['correlation_id'] ?? null,
                dispatch: true,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ERROR'], 422);
        }

        return response()->json(['data' => $run->toPublicArray(), 'serpro_call' => 'QUEUED'], 201);
    }

    /** Lista descritores de resolução locais, nunca Base64 nem conteúdo de cofre. */
    public function regimeResolutions(int $client, Request $request): JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient((int) $tenant->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json([
            'data' => $this->regimes->listResolutions(
                $tenant,
                $model,
                $request->integer('year') ?: null,
            ),
            'provenance' => ['source' => 'LOCAL_PROJECTION', 'serpro_called' => false],
        ]);
    }

    private function findClient(int $tenantId, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($clientId)
            ->first();
    }

    private function rejectClientTenantId(Request $request): ?JsonResponse
    {
        $suppliedAtTopLevel = $request->attributes->get(
            EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED,
        ) === true;
        $suppliedNested = $this->containsTenantIdKey($request->query->all())
            || $this->containsTenantIdKey($request->request->all())
            || ($request->isJson() && $request->json() !== null
                && $this->containsTenantIdKey($request->json()->all()));

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

    private function assertCanRead(): void
    {
        if ($this->currentTenant->role() === null) {
            abort(403, 'Perfil não resolvido.');
        }
    }

    private function assertCanWrite(): void
    {
        $role = $this->currentTenant->role();
        if ($role === null || ! in_array($role, [TenantRole::TenantAdmin, TenantRole::TenantUser], true)) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }

    private function assertModuleEnabled(): void
    {
        $tenant = $this->currentTenant->tenant();
        if (! FeatureFlags::isModuleEnabled(SimplesMeiCatalog::MODULE, $tenant->id)
            && ! (bool) config('fiscal_monitoring.enabled', false)) {
            abort(403, 'Módulo simples_mei desabilitado.');
        }
    }
}
