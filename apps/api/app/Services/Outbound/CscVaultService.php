<?php

namespace App\Services\Outbound;

use App\Contracts\SecureObjectStore;
use App\Models\OutboundCaptureProfile;
use App\Services\Audit\AuditLogger;
use RuntimeException;

/**
 * Grava/substitui CSC e ID CSC no cofre por estabelecimento+ambiente.
 * Leitura do valor restrita a ADMIN (endpoint dedicado); não registra o token em auditoria/log.
 */
final class CscVaultService
{
    public function __construct(
        private readonly SecureObjectStore $store,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{configured: bool, csc_id: ?string, configured_at: ?string, csc: ?string}
     */
    public function storeCsc(
        OutboundCaptureProfile $profile,
        string $cscToken,
        string $cscId,
        int $userId,
    ): array {
        $cscToken = trim($cscToken);
        $cscId = strtoupper(trim($cscId));

        if ($cscToken === '' || $cscId === '') {
            throw new RuntimeException('CSC e ID CSC são obrigatórios.');
        }

        if ($profile->model->value !== '65') {
            throw new RuntimeException('CSC só se aplica ao modelo 65 (NFC-e).');
        }

        $metadata = [
            'office_id' => $profile->office_id,
            'establishment_id' => $profile->establishment_id,
            'environment' => $profile->environment,
            'kind' => 'csc',
        ];

        $objectId = $this->store->put($cscToken, $metadata);

        // Remove objeto anterior se existir (substituição)
        $previous = $profile->csc_vault_object_id;
        if ($previous !== null && $previous !== $objectId) {
            try {
                $this->store->delete($previous);
            } catch (\Throwable) {
                // não bloquear substituição
            }
        }

        $profile->forceFill([
            'csc_vault_object_id' => $objectId,
            'csc_id' => $cscId,
            'csc_configured' => true,
            'csc_configured_at' => now(),
        ])->save();

        // Auditoria sem o token do CSC
        $this->audit->record(
            'outbound.csc.replaced',
            'SUCCESS',
            $profile,
            [
                'profile_id' => $profile->id,
                'establishment_id' => $profile->establishment_id,
                'environment' => $profile->environment,
                'csc_id' => $cscId,
                'configured' => true,
            ],
            $userId,
            $profile->office_id,
        );

        return $this->revealCsc($profile->fresh() ?? $profile, $userId);
    }

    /**
     * Materializa CSC em memória (jobs / consulta mutante / exibição ADMIN).
     */
    public function materializeCsc(OutboundCaptureProfile $profile): ?string
    {
        if (! $profile->csc_configured || $profile->csc_vault_object_id === null) {
            return null;
        }

        $metadata = [
            'office_id' => $profile->office_id,
            'establishment_id' => $profile->establishment_id,
            'environment' => $profile->environment,
            'kind' => 'csc',
        ];

        return $this->store->get($profile->csc_vault_object_id, $metadata);
    }

    /**
     * Metadados + valor do CSC para ADMIN na UI (nunca logar o valor).
     * Auditoria `outbound.csc.revealed` sem o token; autorização ADMIN+2FA no controller.
     *
     * @return array{configured: bool, csc_id: ?string, configured_at: ?string, csc: ?string}
     */
    public function revealCsc(OutboundCaptureProfile $profile, ?int $userId = null): array
    {
        $public = $profile->cscPublicState();
        $csc = $this->materializeCsc($profile);

        // Trilha sem o valor do CSC (AuditLogger também redige chaves sensíveis).
        $this->audit->record(
            'outbound.csc.revealed',
            'SUCCESS',
            $profile,
            [
                'profile_id' => $profile->id,
                'establishment_id' => $profile->establishment_id,
                'environment' => $profile->environment,
                'csc_id' => $public['csc_id'],
                'configured' => $public['configured'],
                'revealed' => $csc !== null,
            ],
            $userId,
            $profile->office_id,
        );

        return [
            'configured' => $public['configured'],
            'csc_id' => $public['csc_id'],
            'configured_at' => $public['configured_at'],
            'csc' => $csc,
        ];
    }
}
