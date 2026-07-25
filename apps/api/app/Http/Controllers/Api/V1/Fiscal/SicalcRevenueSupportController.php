<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOfficeContext;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Guides\SicalcRevenueSupportQueryService;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/** API tenant-scoped para apoio de receitas SICALC 5.2. */
final class SicalcRevenueSupportController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly SicalcRevenueSupportQueryService $queries,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function history(Request $request, int $client): JsonResponse
    {
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $model = $this->findClient($this->currentOffice->office()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanRead($request, $model);
        try {
            return response()->json(['data' => $this->queries->history($this->currentOffice->office(), $model, $request->query('codigo_receita'))]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_REVENUE_CODE'], 422);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Histórico de apoio SICALC indisponível.', 'code' => 'HISTORY_ERROR'], 422);
        }
    }

    public function consult(Request $request, int $client): JsonResponse
    {
        $this->assertModuleEnabled();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $validated = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'codigo_receita' => ['required', 'string', 'regex:/^[0-9]{1,16}$/'],
        ]);
        $model = $this->findClient($this->currentOffice->office()->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }
        $this->assertCanWrite($request, $model);
        try {
            $run = $this->queries->enqueueManualConsult($this->currentOffice->office(), $model, $validated['codigo_receita'], $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_REVENUE_CODE'], 422);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Consulta de apoio SICALC indisponível.', 'code' => 'SICALC_UNAVAILABLE'], 422);
        }

        return response()->json(['data' => $run], 201);
    }

    private function findClient(int $officeId, int $clientId): ?Client
    {
        return Client::query()->withoutGlobalScopes()->where('office_id', $officeId)->whereKey($clientId)->first();
    }

    private function clientNotFound(): JsonResponse
    {
        return response()->json(['message' => 'Cliente não encontrado no escritório atual.', 'code' => 'CLIENT_NOT_FOUND'], 404);
    }

    private function rejectClientOfficeId(Request $request): ?JsonResponse
    {
        $supplied = $request->attributes->get(EnsureOfficeContext::CLIENT_OFFICE_ID_SUPPLIED) === true
            || $this->containsOfficeIdKey($request->query->all()) || $this->containsOfficeIdKey($request->request->all())
            || ($request->isJson() && $request->json() !== null && $this->containsOfficeIdKey($request->json()->all()));

        return $supplied ? response()->json([
            'message' => 'office_id não é aceito; o escritório é obtido do contexto autenticado.',
            'code' => 'CLIENT_OFFICE_ID_REJECTED',
        ], 422) : null;
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
        $office = $this->currentOffice->office();
        if (! FeatureFlags::isModuleEnabled('guias', $office->id) && ! (bool) config('fiscal_monitoring.enabled', false)) {
            abort(403, 'Módulo guias desabilitado.');
        }
    }
}
