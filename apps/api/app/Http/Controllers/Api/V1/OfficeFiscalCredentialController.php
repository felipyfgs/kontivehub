<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OfficeCredential;
use App\Models\PlatformPrivilegedAuditEvent;
use App\Policies\OfficeFiscalCredentialPolicy;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\OfficeCredentialService;
use App\Services\Certificates\OfficeFiscalIdentityService;
use App\Services\Platform\PlatformPrivilegedAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Identidade fiscal e A1 do escritório.
 * Sem rota de download/recuperação de PFX/senha/PEM.
 */
class OfficeFiscalCredentialController extends Controller
{
    public function showIdentity(
        OfficeFiscalIdentityService $identities,
        OfficeCredentialService $credentials,
    ): JsonResponse {
        $this->authorizeView();

        $identity = $identities->activeForCurrentOffice();
        $credential = $identity !== null ? $credentials->activeFor($identity) : null;

        return response()->json([
            'data' => [
                'identity' => $identity?->toPublicArray(),
                'credential' => $credential?->toPublicArray(),
            ],
        ]);
    }

    public function storeIdentity(
        Request $request,
        OfficeFiscalIdentityService $identities,
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
            $audit->record('office_fiscal_identity.upsert', 'FAILED', null, [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $audit->record('office_fiscal_identity.upsert', 'SUCCESS', $identity, [
            'cnpj' => $identity->cnpj,
            'root_cnpj' => $identity->root_cnpj,
            'fingerprint' => null,
        ]);

        return response()->json(['data' => $identity->toPublicArray()], 201);
    }

    public function storeCredential(
        Request $request,
        OfficeFiscalIdentityService $identities,
        OfficeCredentialService $credentials,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorizeManage();

        $data = $request->validate([
            'pfx' => ['required', 'file', 'max:5120'],
            'password' => ['required', 'string'],
        ]);

        $identity = $identities->activeForCurrentOffice();
        if ($identity === null) {
            return response()->json([
                'message' => 'Cadastre a identidade fiscal do escritório antes do certificado A1.',
            ], 422);
        }

        try {
            $binary = file_get_contents($data['pfx']->getRealPath());
            if ($binary === false) {
                throw new RuntimeException('Falha ao ler arquivo PFX.');
            }

            $credential = $credentials->activate($identity, $binary, $data['password']);
            // Descarta buffers em claro o quanto antes (password só na request).
            unset($binary, $data['password']);
        } catch (RuntimeException $e) {
            $audit->record('office_credential.activate', 'FAILED', $identity, [
                'message' => $e->getMessage() ?: 'Falha ao ativar certificado do escritório.',
            ]);

            return response()->json([
                'message' => $e->getMessage() ?: 'Falha ao ativar certificado do escritório.',
            ], 422);
        } catch (Throwable $e) {
            report($e);
            $audit->record('office_credential.activate', 'FAILED', $identity, [
                'message' => 'Falha ao ativar certificado do escritório.',
            ]);

            return response()->json([
                'message' => 'Falha ao ativar certificado do escritório.',
            ], 422);
        }

        $audit->record('office_credential.activate', 'SUCCESS', $credential, [
            'office_fiscal_identity_id' => $identity->id,
            'fingerprint_sha256' => $credential->fingerprint_sha256,
            'holder_cnpj' => $credential->holder_cnpj,
            'valid_to' => $credential->valid_to?->toIso8601String(),
            'purpose' => $credential->purpose->value,
        ]);

        app(PlatformPrivilegedAuditor::class)->recordIfPrivileged(
            PlatformPrivilegedAuditEvent::ACTION_MUTATE,
            PlatformPrivilegedAuditEvent::RESULT_SUCCESS,
            $credential,
            ['reason' => 'office_credential.activate'],
        );

        return response()->json(['data' => $credential->toPublicArray()], 201);
    }

    public function revokeCredential(
        OfficeCredential $credential,
        OfficeCredentialService $credentials,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorizeManage();
        $policy = app(OfficeFiscalCredentialPolicy::class);
        if (! $policy->manageCredential(auth()->user(), $credential)) {
            abort(403);
        }

        $credentials->revoke($credential);

        $audit->record('office_credential.revoke', 'SUCCESS', $credential, [
            'fingerprint_sha256' => $credential->fingerprint_sha256,
            'purpose' => $credential->purpose->value,
        ]);

        app(PlatformPrivilegedAuditor::class)->recordIfPrivileged(
            PlatformPrivilegedAuditEvent::ACTION_MUTATE,
            PlatformPrivilegedAuditEvent::RESULT_SUCCESS,
            $credential,
            ['reason' => 'office_credential.revoke'],
        );

        return response()->json([
            'data' => $credential->fresh()?->toPublicArray(),
        ]);
    }

    private function authorizeView(): void
    {
        $policy = app(OfficeFiscalCredentialPolicy::class);
        if (! $policy->view(auth()->user())) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }

    private function authorizeManage(): void
    {
        $policy = app(OfficeFiscalCredentialPolicy::class);
        if (! $policy->manage(auth()->user())) {
            abort(403, 'Apenas administradores com 2FA recente podem gerenciar a identidade/A1 do escritório.');
        }
    }
}
