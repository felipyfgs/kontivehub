<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Policies\TenantFiscalCredentialPolicy;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\TenantFiscalIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/** Identidade fiscal usada pelo canal autXML do escritório. */
class TenantFiscalCredentialController extends Controller
{
    public function showIdentity(TenantFiscalIdentityService $identities): JsonResponse
    {
        $this->authorizeView();

        $identity = $identities->activeForCurrentTenant();

        return response()->json([
            'data' => [
                'identity' => $identity?->toPublicArray(),
            ],
        ]);
    }

    public function storeIdentity(
        Request $request,
        TenantFiscalIdentityService $identities,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorizeManage();

        $data = $request->validate([
            'cnpj' => ['required', 'string', 'max:18'],
            'legal_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $identity = $identities->upsertActive($data['cnpj'], $data['legal_name'] ?? null);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $audit->record('tenant_fiscal_identity.upsert', 'FAILED', null, [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $audit->record('tenant_fiscal_identity.upsert', 'SUCCESS', $identity, [
            'cnpj' => $identity->cnpj,
            'root_cnpj' => $identity->root_cnpj,
            'fingerprint' => null,
        ]);

        return response()->json(['data' => $identity->toPublicArray()], 201);
    }

    private function authorizeView(): void
    {
        $policy = app(TenantFiscalCredentialPolicy::class);
        if (! $policy->view(auth()->user())) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }

    private function authorizeManage(): void
    {
        $policy = app(TenantFiscalCredentialPolicy::class);
        if (! $policy->manage(auth()->user())) {
            abort(403, 'Apenas administradores com confirmação recente de senha podem gerenciar a identidade fiscal do escritório.');
        }
    }
}
