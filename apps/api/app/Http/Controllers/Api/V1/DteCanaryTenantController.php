<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\SerproDteCanaryRequest;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Integra\DteCanaryTenantService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Confirmação Tenant ADMIN e leitura do resultado DTE no tenant.
 * NÃO importa App\Services\Serpro\* — usa fachada Integra.
 * NÃO aceita tenant_id do client.
 */
class DteCanaryTenantController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly DteCanaryTenantService $dteCanary,
        private readonly RecentPasswordConfirmationGate $passwordGate,
    ) {}

    public function pending(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        if ($tenant === null) {
            return response()->json(['message' => 'Usuário sem escritório ativo.'], 403);
        }

        $row = $this->dteCanary->findPendingForTenant((int) $tenant->id);

        return response()->json([
            'data' => $row?->toGlobalSanitizedArray(),
        ]);
    }

    public function confirmParticipation(Request $request, SerproDteCanaryRequest $serproDteCanaryRequest): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        if ($tenant === null) {
            return response()->json(['message' => 'Usuário sem escritório ativo.'], 403);
        }

        if ($request->exists('tenant_id')) {
            return response()->json([
                'message' => 'tenant_id do client não é aceito; use o Tenant corrente.',
                'code' => 'forbidden_field',
            ], 422);
        }

        $confirmed = $this->passwordGate->isRecentlyConfirmed($request->user(), $request);
        if (! $confirmed) {
            return response()->json([
                'message' => 'Reconfirmação de senha obrigatória (15 minutos).',
                'code' => 'password_confirmation_required',
            ], 403);
        }

        try {
            $row = $this->dteCanary->approveAsTenantAdmin(
                $serproDteCanaryRequest,
                $request->user(),
                $tenant,
                true,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'dte_tenant_confirm_error'], 422);
        }

        return response()->json(['data' => $row->toGlobalSanitizedArray()]);
    }

    public function result(Request $request, SerproDteCanaryRequest $serproDteCanaryRequest): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        if ($tenant === null) {
            return response()->json(['message' => 'Usuário sem escritório ativo.'], 403);
        }

        if ($request->exists('tenant_id')) {
            return response()->json([
                'message' => 'tenant_id do client não é aceito.',
                'code' => 'forbidden_field',
            ], 422);
        }

        // Qualquer membership ativa no Tenant piloto.
        try {
            $data = $this->dteCanary->tenantResult(
                $serproDteCanaryRequest,
                $request->user(),
                $tenant,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'dte_result_forbidden'], 403);
        }

        return response()->json(['data' => $data]);
    }

    private function assertAdmin(): void
    {
        $role = $this->currentTenant->role();
        if ($role !== TenantRole::TenantAdmin) {
            abort(403, 'Somente Tenant ADMIN.');
        }
    }
}
