<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\OfficeRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOfficeContext;
use App\Models\Client;
use App\Services\Fiscal\SimplesMei\RegimeApplicabilityService;
use App\Services\Fiscal\SimplesMei\SimplesMeiCatalog;
use App\Services\Fiscal\SimplesMei\SimplesMeiQueryService;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SimplesMeiController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
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
                $this->currentOffice->office()->id,
            ),
            'mutating_enabled' => FeatureFlags::isMutatingEnabled(
                SimplesMeiCatalog::MODULE,
                $this->currentOffice->office()->id,
            ),
        ]);
    }

    public function regimes(int $client): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $model = $this->findClient($office->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $periods = $this->regimes->listPeriods($office, $model);

        return response()->json([
            'data' => $periods->map->toPublicArray()->values(),
            'current_tax_regime' => $model->tax_regime,
        ]);
    }

    public function competences(int $client, Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $model = $this->findClient($office->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $family = $request->query('regime_family');
        $items = $this->queries->listCompetences(
            $office,
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
        $office = $this->currentOffice->office();
        $model = $this->findClient($office->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $system = $request->query('system_code');
        $page = $this->queries->listSnapshots(
            $office,
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
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $client = $this->findClient((int) $office->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $run = $this->queries->enqueueConsult(
                office: $office,
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
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();
        $model = $this->findClient((int) $office->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json([
            'data' => $this->regimes->listCalendarOptions($office, $model),
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
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $client = $this->findClient((int) $office->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $run = $this->queries->enqueueConsult(
                office: $office,
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
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();
        $model = $this->findClient((int) $office->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json([
            'data' => $this->regimes->listRegimeOptions($office, $model),
            'provenance' => ['source' => 'LOCAL_PROJECTION', 'serpro_called' => false],
        ]);
    }

    /** Agenda CONSULTARRESOLUCAO104; a leitura da resolução permanece local. */
    public function consultRegimeResolution(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();
        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $client = $this->findClient((int) $office->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $run = $this->queries->enqueueConsult(
                office: $office,
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
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $office = $this->currentOffice->office();
        $model = $this->findClient((int) $office->id, $client);
        if ($model === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json([
            'data' => $this->regimes->listResolutions(
                $office,
                $model,
                $request->integer('year') ?: null,
            ),
            'provenance' => ['source' => 'LOCAL_PROJECTION', 'serpro_called' => false],
        ]);
    }

    public function consult(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $this->assertModuleEnabled();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'system_code' => ['required', 'string', 'max:40'],
            'service_code' => ['required', 'string', 'max:80'],
            'operation_code' => ['sometimes', 'string', 'max:80'],
            'period_key' => ['sometimes', 'nullable', 'string', 'max:20'],
            'correlation_id' => ['sometimes', 'string', 'max:64'],
            'dispatch' => ['sometimes', 'boolean'],
        ]);

        $client = $this->findClient($office->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $run = $this->queries->enqueueConsult(
                office: $office,
                client: $client,
                systemCode: $data['system_code'],
                serviceCode: $data['service_code'],
                operationCode: $data['operation_code'] ?? 'MONITOR',
                periodKey: $data['period_key'] ?? null,
                actorId: $request->user()?->id,
                correlationId: $data['correlation_id'] ?? null,
                dispatch: (bool) ($data['dispatch'] ?? true),
            );
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'não catalogada') ? 422 : 422;

            return response()->json([
                'message' => $e->getMessage(),
                'code' => str_contains($e->getMessage(), 'não catalogada') ? 'UNSUPPORTED' : 'ERROR',
            ], $status);
        }

        return response()->json(['data' => $run->toPublicArray()], 201);
    }

    public function generateDas(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $this->assertModuleEnabled();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'regime_family' => ['sometimes', 'string', 'in:SIMPLES_NACIONAL,MEI'],
            'period_key' => ['sometimes', 'nullable', 'string', 'max:20'],
            'correlation_id' => ['sometimes', 'string', 'max:64'],
            'dispatch' => ['sometimes', 'boolean'],
        ]);

        $client = $this->findClient($office->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $run = $this->queries->enqueueDasGeneration(
            office: $office,
            client: $client,
            regimeFamily: $data['regime_family'] ?? 'SIMPLES_NACIONAL',
            periodKey: $data['period_key'] ?? null,
            actorId: $request->user()?->id,
            correlationId: $data['correlation_id'] ?? null,
            dispatch: (bool) ($data['dispatch'] ?? true),
        );

        return response()->json(['data' => $run->toPublicArray()], 201);
    }

    public function transmit(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $this->assertModuleEnabled();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'service_code' => ['required', 'string', 'in:PGDASD,DEFIS,DASN_SIMEI'],
            'period_key' => ['sometimes', 'nullable', 'string', 'max:20'],
            'correlation_id' => ['sometimes', 'string', 'max:64'],
            'dispatch' => ['sometimes', 'boolean'],
        ]);

        $client = $this->findClient($office->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        // Piloto: ainda enfileira para auditar/bloquear no adapter (sem chamar SERPRO se mutante OFF)
        $run = $this->queries->enqueueTransmit(
            office: $office,
            client: $client,
            serviceCode: $data['service_code'],
            periodKey: $data['period_key'] ?? null,
            actorId: $request->user()?->id,
            correlationId: $data['correlation_id'] ?? null,
            dispatch: (bool) ($data['dispatch'] ?? true),
        );

        return response()->json([
            'data' => $run->toPublicArray(),
            'warning' => 'Transmissão classificada como mutante; bloqueada no piloto se flags OFF.',
        ], 201);
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
        if ($this->currentOffice->role() === null) {
            abort(403, 'Perfil não resolvido.');
        }
    }

    private function assertCanWrite(): void
    {
        $role = $this->currentOffice->role();
        if ($role === null || ! in_array($role, [OfficeRole::Admin, OfficeRole::Operator], true)) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }

    private function assertModuleEnabled(): void
    {
        $office = $this->currentOffice->office();
        if (! FeatureFlags::isModuleEnabled(SimplesMeiCatalog::MODULE, $office->id)
            && ! (bool) config('fiscal_monitoring.enabled', false)) {
            abort(403, 'Módulo simples_mei desabilitado.');
        }
    }
}
