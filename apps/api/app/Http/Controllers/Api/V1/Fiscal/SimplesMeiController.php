<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\DTO\Fiscal\Monitoring\SimplesMeiCatalogData;
use App\DTO\Fiscal\Monitoring\SimplesMeiLocalProjectionData;
use App\DTO\Fiscal\Monitoring\SimplesMeiRegimePeriodsData;
use App\DTO\Fiscal\Monitoring\SimplesMeiSnapshotPageData;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\Fiscal\Mutations\EnqueueSimplesMeiClientConsultRequest;
use App\Http\Requests\Fiscal\Mutations\EnqueueSimplesMeiYearConsultRequest;
use App\Http\Requests\Fiscal\Monitoring\ListSimplesMeiCompetencesRequest;
use App\Http\Requests\Fiscal\Monitoring\ListSimplesMeiRegimeResolutionsRequest;
use App\Http\Requests\Fiscal\Monitoring\ListSimplesMeiSnapshotsRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewSimplesMeiCatalogRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewSimplesMeiClientRequest;
use App\Http\Resources\Fiscal\FiscalCompetenceResource;
use App\Http\Resources\Fiscal\FiscalSnapshotPageResource;
use App\Http\Resources\Fiscal\SimplesMeiCatalogResource;
use App\Http\Resources\Fiscal\SimplesMeiLocalProjectionResource;
use App\Http\Resources\Fiscal\SimplesMeiRegimePeriodsResource;
use App\Models\Client;
use App\Services\Fiscal\SimplesMei\RegimeApplicabilityService;
use App\Services\Fiscal\SimplesMei\SimplesMeiCatalog;
use App\Services\Fiscal\SimplesMei\SimplesMeiQueryService;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class SimplesMeiController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly SimplesMeiQueryService $queries,
        private readonly RegimeApplicabilityService $regimes,
    ) {}

    public function catalog(
        ViewSimplesMeiCatalogRequest $request,
    ): SimplesMeiCatalogResource {
        return new SimplesMeiCatalogResource(new SimplesMeiCatalogData(
            operations: SimplesMeiCatalog::toPublicCatalog(),
            module: SimplesMeiCatalog::MODULE,
            moduleEnabled: FeatureFlags::isModuleEnabled(
                SimplesMeiCatalog::MODULE,
                $this->currentTenant->tenant()->id,
            ),
            mutatingEnabled: FeatureFlags::isMutatingEnabled(
                SimplesMeiCatalog::MODULE,
                $this->currentTenant->tenant()->id,
            ),
        ));
    }

    public function regimes(
        ViewSimplesMeiClientRequest $request,
        int $client,
        FindFiscalClientAction $findClient,
    ): JsonResponse|SimplesMeiRegimePeriodsResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return new SimplesMeiRegimePeriodsResource(
            new SimplesMeiRegimePeriodsData(
                periods: $this->regimes->listPeriods($tenant, $model),
                currentTaxRegime: $model->tax_regime,
            ),
        );
    }

    public function competences(
        int $client,
        ListSimplesMeiCompetencesRequest $request,
        FindFiscalClientAction $findClient,
    ): JsonResponse|AnonymousResourceCollection {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }
        $filters = $request->filters();

        return FiscalCompetenceResource::collection(
            $this->queries->listCompetences(
                $tenant,
                $model,
                $filters->regimeFamily,
            ),
        );
    }

    public function snapshots(
        int $client,
        ListSimplesMeiSnapshotsRequest $request,
        FindFiscalClientAction $findClient,
    ): JsonResponse|FiscalSnapshotPageResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }
        $filters = $request->filters();

        return new FiscalSnapshotPageResource(
            new SimplesMeiSnapshotPageData(
                $this->queries->listSnapshots(
                    $tenant,
                    $model,
                    $filters->perPage,
                    $filters->systemCode,
                ),
            ),
        );
    }

    /**
     * Agenda apenas CONSULTARANOSCALENDARIOS102; GET continua sempre local.
     */
    public function consultRegimeCalendar(EnqueueSimplesMeiClientConsultRequest $request): JsonResponse
    {
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();

        $client = $this->findClient((int) $tenant->id, $request->clientId());
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
                correlationId: $request->correlationId(),
                dispatch: true,
            );
        } catch (RuntimeException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text, 'code' => 'ERROR'], 422);
        }

        return response()->json([
            'data' => $run->toPublicArray(),
            'serpro_call' => 'QUEUED',
        ], 201);
    }

    /** Lista somente a projeção local produzida por CONSULTARANOSCALENDARIOS102. */
    public function regimeCalendar(
        int $client,
        ViewSimplesMeiClientRequest $request,
        FindFiscalClientAction $findClient,
    ): JsonResponse|SimplesMeiLocalProjectionResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return new SimplesMeiLocalProjectionResource(
            new SimplesMeiLocalProjectionData(
                $this->regimes->listCalendarOptions($tenant, $model),
            ),
        );
    }

    /** Agenda CONSULTAROPCAOREGIME103 para um ano-calendário explícito. */
    public function consultRegimeOption(EnqueueSimplesMeiYearConsultRequest $request): JsonResponse
    {
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $client = $this->findClient((int) $tenant->id, $request->clientId());
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
                periodKey: (string) $request->year(),
                actorId: $request->user()?->id,
                correlationId: $request->correlationId(),
                dispatch: true,
            );
        } catch (RuntimeException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text, 'code' => 'ERROR'], 422);
        }

        return response()->json(['data' => $run->toPublicArray(), 'serpro_call' => 'QUEUED'], 201);
    }

    /** Lista somente a projeção local produzida pelo serviço 103. */
    public function regimeOptions(
        int $client,
        ViewSimplesMeiClientRequest $request,
        FindFiscalClientAction $findClient,
    ): JsonResponse|SimplesMeiLocalProjectionResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return new SimplesMeiLocalProjectionResource(
            new SimplesMeiLocalProjectionData(
                $this->regimes->listRegimeOptions($tenant, $model),
            ),
        );
    }

    /** Agenda CONSULTARRESOLUCAO104; a leitura da resolução permanece local. */
    public function consultRegimeResolution(EnqueueSimplesMeiYearConsultRequest $request): JsonResponse
    {
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $client = $this->findClient((int) $tenant->id, $request->clientId());
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
                periodKey: (string) $request->year(),
                actorId: $request->user()?->id,
                correlationId: $request->correlationId(),
                dispatch: true,
            );
        } catch (RuntimeException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text, 'code' => 'ERROR'], 422);
        }

        return response()->json(['data' => $run->toPublicArray(), 'serpro_call' => 'QUEUED'], 201);
    }

    /** Lista descritores de resolução locais, nunca Base64 nem conteúdo de cofre. */
    public function regimeResolutions(
        int $client,
        ListSimplesMeiRegimeResolutionsRequest $request,
        FindFiscalClientAction $findClient,
    ): JsonResponse|SimplesMeiLocalProjectionResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return new SimplesMeiLocalProjectionResource(
            new SimplesMeiLocalProjectionData(
                $this->regimes->listResolutions(
                    $tenant,
                    $model,
                    $request->year(),
                ),
            ),
        );
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
