<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Mutations\FiscalMutationException;
use App\Services\Fiscal\Mutations\FiscalMutationService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preflight, execução e reconciliação de operações fiscais mutantes (13.2–13.6).
 */
class FiscalMutationController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly FiscalMutationService $mutations,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function preflight(Request $request): JsonResponse
    {
        $user = $this->assertPermission($request, TenantPermission::FiscalMutationsExecute);
        $tenant = $this->currentTenant->tenant();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'solution_code' => ['required', 'string', 'max:80'],
            'service_code' => ['required', 'string', 'max:120'],
            'operation_code' => ['required', 'string', 'max:120'],
            'operation_key' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9_]+(?:\.[a-z0-9_]+)+$/'],
            'competence_period_key' => ['nullable', 'string', 'max:20'],
            'idempotency_key' => ['nullable', 'string', 'max:160'],
            'environment' => ['nullable', 'string', 'max:20'],
            'module' => ['nullable', 'string', 'max:40'],
            'payload' => ['nullable', 'array'],
        ]);

        $client = $this->resolveClient($tenant->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $idempotency = $data['idempotency_key']
            ?? $request->header('Idempotency-Key');

        $result = $this->mutations->preflight(
            tenant: $tenant,
            client: $client,
            user: $user,
            solutionCode: $data['solution_code'],
            serviceCode: $data['service_code'],
            operationCode: $data['operation_code'],
            operationKey: $data['operation_key'],
            competencePeriodKey: $data['competence_period_key'] ?? null,
            idempotencyKey: is_string($idempotency) ? $idempotency : null,
            environment: $data['environment'] ?? null,
            requestPayload: $data['payload'] ?? [],
            module: $data['module'] ?? null,
        );

        $status = $result->eligible ? 200 : 422;

        return response()->json(['data' => $result->toArray()], $status);
    }

    public function execute(Request $request): JsonResponse
    {
        $user = $this->assertPermission($request, TenantPermission::FiscalMutationsExecute);
        $tenant = $this->currentTenant->tenant();

        $headerIdempotency = $request->header('Idempotency-Key');
        if (! $request->filled('idempotency_key')
            && is_string($headerIdempotency)
            && trim($headerIdempotency) !== ''
        ) {
            $request->merge(['idempotency_key' => $headerIdempotency]);
        }

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'solution_code' => ['required', 'string', 'max:80'],
            'service_code' => ['required', 'string', 'max:120'],
            'operation_code' => ['required', 'string', 'max:120'],
            'operation_key' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9_]+(?:\.[a-z0-9_]+)+$/'],
            'competence_period_key' => ['nullable', 'string', 'max:20'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            'preflight_token' => ['required', 'string', 'max:64'],
            'environment' => ['nullable', 'string', 'max:20'],
            'module' => ['nullable', 'string', 'max:40'],
            'payload' => ['nullable', 'array'],
            'confirmation_phrase' => ['required', 'string', 'max:120'],
            'confirmed' => ['required', 'boolean'],
        ]);

        $client = $this->resolveClient($tenant->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            $operation = $this->mutations->execute(
                tenant: $tenant,
                client: $client,
                user: $user,
                solutionCode: $data['solution_code'],
                serviceCode: $data['service_code'],
                operationCode: $data['operation_code'],
                operationKey: $data['operation_key'],
                confirmationPhrase: $data['confirmation_phrase'],
                confirmed: (bool) $data['confirmed'],
                competencePeriodKey: $data['competence_period_key'] ?? null,
                idempotencyKey: $data['idempotency_key'],
                preflightToken: $data['preflight_token'],
                environment: $data['environment'] ?? null,
                requestPayload: $data['payload'] ?? [],
                module: $data['module'] ?? null,
            );
        } catch (FiscalMutationException $e) {
            return response()->json($e->toArray(), $e->httpStatus());
        }

        return response()->json(['data' => $operation->toPublicArray()], 201);
    }

    public function show(Request $request, int $mutation): JsonResponse
    {
        $this->assertPermission($request, TenantPermission::FiscalMonitoringView);
        $tenant = $this->currentTenant->tenant();
        $model = $this->mutations->findForTenant($tenant, $mutation);
        if ($model === null) {
            return response()->json(['message' => 'Operação não encontrada.'], 404);
        }

        return response()->json(['data' => $model->toPublicArray()]);
    }

    public function reconcile(Request $request, int $mutation): JsonResponse
    {
        $user = $this->assertPermission($request, TenantPermission::FiscalMutationsExecute);
        $tenant = $this->currentTenant->tenant();

        $model = $this->mutations->findForTenant($tenant, $mutation);
        if ($model === null) {
            return response()->json(['message' => 'Operação não encontrada.'], 404);
        }

        try {
            $result = $this->mutations->reconcile($tenant, $model, $user);
        } catch (FiscalMutationException $e) {
            return response()->json($e->toArray(), $e->httpStatus());
        }

        return response()->json(['data' => $result->toPublicArray()]);
    }

    private function resolveClient(int $tenantId, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($clientId)
            ->first();
    }

    private function assertPermission(Request $request, TenantPermission $permission): User
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->authorization->allows($user, $permission)) {
            abort(403, 'Sem permissão para esta operação fiscal.');
        }

        return $user;
    }
}
