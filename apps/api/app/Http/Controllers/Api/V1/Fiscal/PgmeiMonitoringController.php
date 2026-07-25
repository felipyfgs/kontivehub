<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOfficeContext;
use App\Http\Requests\Fiscal\Mei\ConsultMeiDebtRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiCommunicationService;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiMonitoringQueryService;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiYear;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PgmeiMonitoringController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly PgmeiMonitoringQueryService $queries,
        private readonly PgmeiCommunicationService $communication,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function history(Request $request, int $client): JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }

        $office = $this->currentOffice->office();
        $model = $this->findClient($office->id, $client);
        if ($model === null) {
            return response()->json([
                'message' => 'Cliente não encontrado no escritório atual.',
                'code' => 'CLIENT_NOT_FOUND',
            ], 404);
        }

        $data = $request->validate([
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
        ]);
        $yearInt = isset($data['year']) ? (int) $data['year'] : null;

        try {
            $data = $this->queries->history($office, $model, $yearInt);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'HISTORY_ERROR'], 422);
        }

        return response()->json(['data' => $data]);
    }

    public function consult(ConsultMeiDebtRequest $request): JsonResponse
    {
        $this->assertCanSync();
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }

        $data = $request->validated();

        $office = $this->currentOffice->office();

        try {
            $runs = $this->queries->enqueueManualConsult(
                $office,
                $data['client_ids'],
                (int) $data['calendar_year'],
                true,
                $request->user()?->id,
            );
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $runs,
            'enqueued_count' => count($runs),
            'year' => PgmeiYear::assertValid((int) $data['calendar_year']),
            'calendar_year' => PgmeiYear::assertValid((int) $data['calendar_year']),
        ], 201);
    }

    public function updatePreferences(Request $request, int $client): JsonResponse
    {
        $this->assertCanManageCommunications();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();
        $model = $this->findClient($office->id, $client);
        if ($model === null) {
            return response()->json([
                'message' => 'Cliente não encontrado no escritório atual.',
                'code' => 'CLIENT_NOT_FOUND',
            ], 404);
        }

        $data = $request->validate([
            'email_enabled' => ['required', 'boolean'],
            'whatsapp_enabled' => ['required', 'boolean'],
            'automatic_requested' => ['required', 'boolean'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        try {
            $this->communication->updatePreferences(
                $office,
                $model,
                $user,
                $data,
            );
        } catch (ConflictHttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'OPTIMISTIC_LOCK_CONFLICT',
            ], 409);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->communication->summary($office, $model),
        ]);
    }

    public function batchPreferences(Request $request): JsonResponse
    {
        $this->assertCanManageCommunications();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();
        $data = $request->validate([
            'client_ids' => ['required', 'array', 'min:1', 'max:100'],
            'client_ids.*' => ['integer', 'distinct'],
            'automatic_requested' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        try {
            $prefs = $this->communication->batchSetAutomatic(
                $office,
                $user,
                $data['client_ids'],
                (bool) $data['automatic_requested'],
            );
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $summaries = $this->communication->summariesForClients(
            $office,
            array_map(static fn ($preference): int => (int) $preference->client_id, $prefs),
        );

        return response()->json([
            'data' => array_values($summaries),
            'updated_count' => count($summaries),
        ]);
    }

    public function preview(Request $request, int $client): JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }

        $office = $this->currentOffice->office();
        $model = $this->findClient($office->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json([
            'data' => $this->communication->preview($office, $model),
        ]);
    }

    public function tracking(Request $request, int $client): JsonResponse
    {
        $this->assertCanRead();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }

        $office = $this->currentOffice->office();
        $model = $this->findClient($office->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json([
            'data' => $this->communication->tracking($office, $model),
        ]);
    }

    public function send(Request $request, int $client): JsonResponse
    {
        $this->assertCanSync();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }

        $office = $this->currentOffice->office();
        $model = $this->findClient($office->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }
        /** @var User $actor */
        $actor = $request->user();
        $input = $request->validate(['period_key' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/']]);
        try {
            $data = $this->communication->requestSend($office, $model, $actor, $input['period_key'] ?? null);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['data' => $data]);
    }

    private function findClient(int $officeId, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereKey($clientId)
            ->first();
    }

    private function rejectClientOfficeId(Request $request): ?JsonResponse
    {
        $suppliedAtTopLevel = $request->attributes->get(
            EnsureOfficeContext::CLIENT_OFFICE_ID_SUPPLIED,
        ) === true;
        $suppliedNested = $this->containsOfficeIdKey($request->query->all())
            || $this->containsOfficeIdKey($request->request->all())
            || ($request->isJson() && $request->json() !== null
                && $this->containsOfficeIdKey($request->json()->all()));

        if (! $suppliedAtTopLevel && ! $suppliedNested) {
            return null;
        }

        return response()->json([
            'message' => 'office_id não é aceito; o escritório é obtido do contexto autenticado.',
            'code' => 'CLIENT_OFFICE_ID_REJECTED',
        ], 422);
    }

    /** @param array<array-key, mixed> $values */
    private function containsOfficeIdKey(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && strtolower($key) === 'office_id') {
                return true;
            }
            if (is_array($value) && $this->containsOfficeIdKey($value)) {
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
        $office = $this->currentOffice->office();
        if (! FeatureFlags::isModuleEnabled('simples_mei', $office->id)
            && ! (bool) config('fiscal_monitoring.enabled', false)) {
            abort(403, 'Módulo simples_mei desabilitado.');
        }
    }
}
