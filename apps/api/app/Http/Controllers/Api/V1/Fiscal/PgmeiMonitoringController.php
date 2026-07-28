<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\Fiscal\Mei\ConsultMeiDebtRequest;
use App\Http\Requests\Fiscal\Monitoring\ListPgmeiHistoryRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewSimplesMeiModuleClientRequest;
use App\Http\Requests\Fiscal\Mutations\BatchAutomaticPreferencesRequest;
use App\Http\Requests\Fiscal\Mutations\OptionalPeriodKeyRequest;
use App\Http\Requests\Fiscal\Mutations\UpdateCommunicationPreferencesRequest;
use App\Http\Resources\Fiscal\FiscalMonitoringDataResource;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiCommunicationService;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiMonitoringQueryService;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiYear;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PgmeiMonitoringController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly PgmeiMonitoringQueryService $queries,
        private readonly PgmeiCommunicationService $communication,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function history(
        ListPgmeiHistoryRequest $request,
        int $client,
        FindFiscalClientAction $findClient,
    ): JsonResponse|FiscalMonitoringDataResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return response()->json([
                'message' => 'Cliente não encontrado no escritório atual.',
                'code' => 'CLIENT_NOT_FOUND',
            ], 404);
        }

        $filters = $request->filters();

        try {
            $data = $this->queries->history($tenant, $model, $filters->year);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'HISTORY_ERROR'], 422);
        }

        return new FiscalMonitoringDataResource($data);
    }

    public function consult(ConsultMeiDebtRequest $request): JsonResponse
    {
        $this->assertCanSync();
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }

        $data = $request->validated();

        $tenant = $this->currentTenant->tenant();

        try {
            $runs = $this->queries->enqueueManualConsult(
                $tenant,
                $data['client_ids'],
                (int) $data['calendar_year'],
                true,
                $request->user()?->id,
            );
        } catch (HttpException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], $e->getStatusCode());
        } catch (RuntimeException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], 422);
        }

        return response()->json([
            'data' => $runs,
            'enqueued_count' => count($runs),
            'calendar_year' => PgmeiYear::assertValid((int) $data['calendar_year']),
        ], 201);
    }

    public function updatePreferences(UpdateCommunicationPreferencesRequest $request, int $client): JsonResponse
    {
        $this->assertCanManageCommunications();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient($tenant->id, $client);
        if ($model === null) {
            return response()->json([
                'message' => 'Cliente não encontrado no escritório atual.',
                'code' => 'CLIENT_NOT_FOUND',
            ], 404);
        }

        $data = $request->preferences();

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        try {
            $this->communication->updatePreferences(
                $tenant,
                $model,
                $user,
                $data,
            );
        } catch (ConflictHttpException $e) {
            return response()->json([
                'message' => ($text = $e->getMessage()),
                'code' => 'OPTIMISTIC_LOCK_CONFLICT',
            ], 409);
        } catch (HttpException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], $e->getStatusCode());
        } catch (RuntimeException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], 422);
        }

        return response()->json([
            'data' => $this->communication->summary($tenant, $model),
        ]);
    }

    public function batchPreferences(BatchAutomaticPreferencesRequest $request): JsonResponse
    {
        $this->assertCanManageCommunications();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }
        $tenant = $this->currentTenant->tenant();

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        try {
            $prefs = $this->communication->batchSetAutomatic(
                $tenant,
                $user,
                $request->clientIds(),
                $request->automaticRequested(),
            );
        } catch (HttpException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], $e->getStatusCode());
        } catch (RuntimeException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], 422);
        }

        $summaries = $this->communication->summariesForClients(
            $tenant,
            array_map(static fn ($preference): int => (int) $preference->client_id, $prefs),
        );

        return response()->json([
            'data' => array_values($summaries),
            'updated_count' => count($summaries),
        ]);
    }

    public function preview(
        ViewSimplesMeiModuleClientRequest $request,
        int $client,
        FindFiscalClientAction $findClient,
    ): JsonResponse|FiscalMonitoringDataResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return new FiscalMonitoringDataResource(
            $this->communication->preview($tenant, $model),
        );
    }

    public function tracking(
        ViewSimplesMeiModuleClientRequest $request,
        int $client,
        FindFiscalClientAction $findClient,
    ): JsonResponse|FiscalMonitoringDataResource {
        $tenant = $this->currentTenant->tenant();
        $model = $findClient->handle($tenant, $request->clientId());
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return new FiscalMonitoringDataResource(
            $this->communication->tracking($tenant, $model),
        );
    }

    public function send(OptionalPeriodKeyRequest $request, int $client): JsonResponse
    {
        $this->assertCanSync();
        if ($rejection = $this->rejectClientTenantId($request)) {
            return $rejection;
        }

        $tenant = $this->currentTenant->tenant();
        $model = $this->findClient($tenant->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }
        /** @var User $actor */
        $actor = $request->user();
        try {
            $data = $this->communication->requestSend($tenant, $model, $actor, $request->periodKey());
        } catch (HttpException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], $e->getStatusCode());
        }

        return response()->json(['data' => $data]);
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
        $this->assertPermission(TenantPermission::FiscalMonitoringView);
    }

    private function assertCanSync(): void
    {
        $this->assertPermission(TenantPermission::FiscalSyncTrigger, 'Sem permissão de sincronização.');
    }

    private function assertCanManageCommunications(): void
    {
        $this->assertPermission(TenantPermission::ClientsManage, 'Sem permissão para alterar comunicação.');
    }

    private function assertPermission(TenantPermission $permission, string $message = 'Perfil não resolvido.'): void
    {
        $actor = request()->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, $permission)) {
            abort(403, $message);
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
