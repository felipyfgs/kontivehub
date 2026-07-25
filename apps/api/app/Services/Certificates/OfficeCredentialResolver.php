<?php

namespace App\Services\Certificates;

use App\Enums\CredentialStatus;
use App\Enums\OfficeCredentialPurpose;
use App\Enums\OfficeFiscalIdentityStatus;
use App\Models\ClientCredential;
use App\Models\OfficeCredential;
use App\Models\OfficeFiscalIdentity;
use RuntimeException;

/**
 * Resolve e materializa somente credencial ACTIVE do escritório para NFE_AUTXML_DISTDFE.
 * Preferência: vínculo de finalidade → canônica; fallback legado por purpose na identity.
 * Rejeita credenciais de clientes ou de outro office.
 */
final class OfficeCredentialResolver
{
    public function __construct(
        private readonly OfficeCredentialService $credentials,
    ) {}

    /**
     * @return array{
     *   identity: OfficeFiscalIdentity|null,
     *   credential: OfficeCredential,
     *   material: array{pfx: string, password: string}
     * }
     */
    public function resolveForAutXml(int $officeId): array
    {
        $identity = OfficeFiscalIdentity::query()
            ->where('office_id', $officeId)
            ->where('status', OfficeFiscalIdentityStatus::Active)
            ->orderBy('id')
            ->first();

        // 1) Vínculo canônico SERPRO/autXML → mesma credencial física.
        $credential = $this->credentials->activeForPurpose(
            $officeId,
            OfficeCredentialPurpose::NfeAutXmlDistDfe,
        );

        // 2) Fallback legado: purpose NFE_AUTXML_DISTDFE na identity.
        if ($credential === null && $identity !== null) {
            $credential = OfficeCredential::query()
                ->where('office_id', $officeId)
                ->where('office_fiscal_identity_id', $identity->id)
                ->where('purpose', OfficeCredentialPurpose::NfeAutXmlDistDfe->value)
                ->where('status', CredentialStatus::Active)
                ->first();
        }

        // 3) Fallback: canônica direta se existir (sem link — migração parcial).
        if ($credential === null) {
            $credential = $this->credentials->activeCanonical($officeId);
        }

        if ($credential === null) {
            if ($identity === null) {
                throw new RuntimeException('Identidade fiscal do escritório ausente ou inativa.');
            }
            throw new RuntimeException('Credencial A1 do escritório ausente ou inativa para autXML.');
        }

        if ($credential->office_id !== $officeId) {
            throw new RuntimeException('Credencial do escritório não pertence ao office solicitado.');
        }

        // Defesa: nunca aceitar ClientCredential neste resolvedor.
        if ($credential instanceof ClientCredential) {
            throw new RuntimeException('Credencial de cliente não pode ser usada no canal autXML.');
        }

        $material = $this->credentials->loadPfxMaterial($credential);
        if ($material === null) {
            throw new RuntimeException('Não foi possível materializar a credencial A1 do escritório.');
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
     *   credential: OfficeCredential,
     *   material: array{pfx: string, password: string}
     * }
     */
    public function resolveForSerproTermSigning(int $officeId): array
    {
        $credential = $this->credentials->activeForPurpose(
            $officeId,
            OfficeCredentialPurpose::SerproTermSigning,
        ) ?? $this->credentials->activeCanonical($officeId);

        if ($credential === null) {
            throw new RuntimeException('Credencial A1 do escritório ausente ou inativa para assinatura do Termo.');
        }

        if ($credential->office_id !== $officeId) {
            throw new RuntimeException('Credencial do escritório não pertence ao office solicitado.');
        }

        $material = $this->credentials->loadPfxMaterial($credential);
        if ($material === null) {
            throw new RuntimeException('Não foi possível materializar a credencial A1 do escritório.');
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
