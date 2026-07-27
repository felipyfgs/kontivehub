<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\SimplesMei\SimplesMeiCatalog;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Parcelamento\ParcelamentoServiceCatalog;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class FiscalMonitoringRunController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly FiscalMonitoringRunService $runs,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $tenant = $this->currentTenant->tenant();

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $clientId = $request->query('client_id');
        $status = $request->query('status');

        $page = $this->runs->paginate(
            $tenant,
            $perPage,
            is_numeric($clientId) ? (int) $clientId : null,
            is_string($status) ? $status : null,
        );

        $page->getCollection()->transform(fn ($r) => $r->toPublicArray());

        return response()->json($page);
    }

    public function show(int $run): JsonResponse
    {
        $this->assertCanRead();
        $tenant = $this->currentTenant->tenant();
        $model = $this->runs->findForTenant($tenant, $run);
        if ($model === null) {
            return response()->json(['message' => 'Execução não encontrada.'], 404);
        }

        return response()->json(['data' => $model->toPublicArray()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $tenant = $this->currentTenant->tenant();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'system_code' => ['required', 'string', 'max:40'],
            'service_code' => ['required', 'string', 'max:80'],
            'operation_code' => ['sometimes', 'string', 'max:80'],
            'correlation_id' => ['sometimes', 'string', 'max:64'],
        ]);

        $systemCode = strtoupper($data['system_code']);
        $serviceCode = strtoupper($data['service_code']);
        $operationCode = strtoupper($data['operation_code'] ?? 'MONITOR');

        // Enqueue genérico é read-only: mutações usam endpoints dedicados com senha recente.
        if ($this->isMutativeOperationCode($systemCode, $serviceCode, $operationCode)) {
            return response()->json([
                'message' => 'Operações mutantes não podem ser enfileiradas pelo endpoint genérico de runs. Use o endpoint dedicado.',
                'code' => 'MUTATING_ENQUEUE_FORBIDDEN',
            ], 403);
        }

        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($data['client_id'])
            ->first();

        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $run = $this->runs->enqueueManual(
                tenant: $tenant,
                client: $client,
                systemCode: $systemCode,
                serviceCode: $serviceCode,
                operationCode: $operationCode,
                actorId: $request->user()?->id,
                correlationId: $data['correlation_id'] ?? null,
                dispatch: true,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $run->toPublicArray()], 201);
    }

    private function isMutativeOperationCode(string $systemCode, string $serviceCode, string $operationCode): bool
    {
        if (in_array($operationCode, DctfwebCodes::mutatingOperations(), true)) {
            return true;
        }

        if (ParcelamentoServiceCatalog::isMutatingOperation($operationCode)) {
            return true;
        }

        $simples = SimplesMeiCatalog::find($systemCode, $serviceCode, $operationCode);
        if ($simples !== null && $simples->mutability->isMutating()) {
            return true;
        }

        return false;
    }

    private function assertCanRead(): void
    {
        $actor = request()->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalMonitoringView)) {
            abort(403, 'Perfil não resolvido.');
        }
    }

    private function assertCanWrite(): void
    {
        $actor = request()->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalSyncTrigger)) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }
}
