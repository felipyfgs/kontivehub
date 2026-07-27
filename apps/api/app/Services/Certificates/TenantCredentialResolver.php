<?php

namespace App\Services\Certificates;

use App\Enums\TenantCredentialPurpose;
use App\Enums\TenantFiscalIdentityStatus;
use App\Models\ClientCredential;
use App\Models\TenantCredential;
use App\Models\TenantFiscalIdentity;
use RuntimeException;

/**
 * Resolve e materializa somente a credencial ativa vinculada ao autXML.
 * Rejeita credenciais de clientes ou de outro tenant.
 */
final class TenantCredentialResolver
{
    public function __construct(
        private readonly TenantCredentialService $credentials,
    ) {}

    /**
     * @return array{
     *   identity: TenantFiscalIdentity|null,
     *   credential: TenantCredential,
     *   material: array{pfx: string, password: string}
     * }
     */
    public function resolveForAutXml(int $tenantId): array
    {
        $identity = TenantFiscalIdentity::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantFiscalIdentityStatus::Active)
            ->orderBy('id')
            ->first();

        $credential = $this->credentials->activeForPurpose(
            $tenantId,
            TenantCredentialPurpose::NfeAutXmlDistDfe,
        );

        if ($credential === null) {
            if ($identity === null) {
                throw new RuntimeException('Identidade fiscal do escritório ausente ou inativa.');
            }
            throw new RuntimeException('certificado do escritório ausente ou inativa para autXML.');
        }

        if ($credential->tenant_id !== $tenantId) {
            throw new RuntimeException('Credencial do escritório não pertence ao tenant solicitado.');
        }

        // Defesa: nunca aceitar ClientCredential neste resolvedor.
        if ($credential instanceof ClientCredential) {
            throw new RuntimeException('Credencial de cliente não pode ser usada no canal autXML.');
        }

        $material = $this->credentials->loadPfxMaterial($credential);
        if ($material === null) {
            throw new RuntimeException('Não foi possível materializar a certificado do escritório.');
        }

        return [
            'identity' => $identity,
            'credential' => $credential,
            'material' => $material,
        ];
    }

    /**
     * Resolve material para assinatura do Termo SERPRO (mesma canônica).
     *
     * @return array{
     *   credential: TenantCredential,
     *   material: array{pfx: string, password: string}
     * }
     */
    public function resolveForSerproTermSigning(int $tenantId): array
    {
        $credential = $this->credentials->activeForPurpose(
            $tenantId,
            TenantCredentialPurpose::SerproTermSigning,
        );

        if ($credential === null) {
            throw new RuntimeException('certificado do escritório ausente ou inativa para assinatura do Termo.');
        }

        if ($credential->tenant_id !== $tenantId) {
            throw new RuntimeException('Credencial do escritório não pertence ao tenant solicitado.');
        }

        $material = $this->credentials->loadPfxMaterial($credential);
        if ($material === null) {
            throw new RuntimeException('Não foi possível materializar a certificado do escritório.');
        }

        return [
            'credential' => $credential,
            'material' => $material,
        ];
    }

    /**
     * Garante que um ClientCredential nunca seja aceito como fonte autXML.
     */
    public function rejectClientCredential(ClientCredential $credential): never
    {
        throw new RuntimeException(
            'Credencial de cliente não pode ser usada no canal NFE_AUTXML_DISTDFE.'
        );
    }
}
