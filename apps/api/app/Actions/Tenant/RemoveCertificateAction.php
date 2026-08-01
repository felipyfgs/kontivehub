<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\CertificateData;
use App\Exceptions\TenantSettingsApiException;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\TenantCredentialService;
use RuntimeException;

final readonly class RemoveCertificateAction
{
    public function __construct(
        private TenantCredentialService $credentials,
        private GetCertificateAction $getCertificate,
        private AuditLogger $audit,
    ) {}

    public function __invoke(int $actorUserId): CertificateData
    {
        try {
            $credential = $this->credentials->remove(
                confirmed: true,
                actorUserId: $actorUserId,
            );
        } catch (RuntimeException $error) {
            throw TenantSettingsApiException::certificateMutationFailed(
                $error->getMessage(),
            );
        }

        if ($credential === null) {
            throw TenantSettingsApiException::certificateNotFound();
        }

        $this->audit->record('tenant_credential.remove', 'SUCCESS', $credential, [
            'fingerprint_sha256' => $credential->fingerprint_sha256,
            'holder_cnpj' => $credential->holder_cnpj,
        ], $actorUserId);

        return $this->getCertificate->fromCredential(
            $credential,
            removed: true,
        );
    }
}
