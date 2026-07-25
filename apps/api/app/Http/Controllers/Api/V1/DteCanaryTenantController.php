<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OfficeRole;
use App\Http\Controllers\Controller;
use App\Models\SerproDteCanaryRequest;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Integra\DteCanaryTenantService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Confirmação Office ADMIN e leitura do resultado DTE no tenant.
 * NÃO importa App\Services\Serpro\* — usa fachada Integra.
 * NÃO aceita office_id do client.
 */
class DteCanaryTenantController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly DteCanaryTenantService $dteCanary,
        private readonly RecentPasswordConfirmationGate $passwordGate,
    ) {}

    public function pending(Request $request): JsonResponse
    {
        $office = $this->currentOffice->office();
        if ($office === null) {
            return response()->json(['message' => 'Usuário sem escritório ativo.'], 403);
        }

        $row = $this->dteCanary->findPendingForOffice((int) $office->id);

        return response()->json([
            'data' => $row?->toGlobalSanitizedArray(),
        ]);
    }

    public function confirmParticipation(Request $request, SerproDteCanaryRequest $serproDteCanaryRequest): JsonResponse
    {
        $office = $this->currentOffice->office();
        if ($office === null) {
            return response()->json(['message' => 'Usuário sem escritório ativo.'], 403);
        }

        if ($request->exists('office_id')) {
            return response()->json([
                'message' => 'office_id do client não é aceito; use o Office corrente.',
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
            $row = $this->dteCanary->approveAsOfficeAdmin(
                $serproDteCanaryRequest,
                $request->user(),
                $office,
                true,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'dte_office_confirm_error'], 422);
        }

        return response()->json(['data' => $row->toGlobalSanitizedArray()]);
    }

    public function result(Request $request, SerproDteCanaryRequest $serproDteCanaryRequest): JsonResponse
    {
        $office = $this->currentOffice->office();
        if ($office === null) {
            return response()->json(['message' => 'Usuário sem escritório ativo.'], 403);
        }

        if ($request->exists('office_id')) {
            return response()->json([
                'message' => 'office_id do client não é aceito.',
                'code' => 'forbidden_field',
            ], 422);
        }

        // Qualquer membership ativa (VIEWER+) no Office piloto
        try {
            $data = $this->dteCanary->tenantResult(
                $serproDteCanaryRequest,
                $request->user(),
                $office,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'dte_result_forbidden'], 403);
        }

        return response()->json(['data' => $data]);
    }

    private function assertAdmin(): void
    {
        $role = $this->currentOffice->role();
        if ($role !== OfficeRole::Admin) {
            abort(403, 'Somente Office ADMIN.');
        }
    }
}
