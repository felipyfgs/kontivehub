<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\CertificateData;
use App\DTO\Tenant\CertificateUploadData;
use App\Exceptions\TenantSettingsApiException;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\TenantCredentialService;
use App\Services\Certificates\TenantTechnicalConsentService;
use App\Services\Integra\SerproTenantActionableStatusService;
use App\Support\CurrentTenant;
use RuntimeException;
use Throwable;

final readonly class ActivateCertificateAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantCredentialService $credentials,
        private TenantTechnicalConsentService $consents,
        private SerproTenantActionableStatusService $actionableStatus,
        private GetConsentStatusAction $getConsentStatus,
        private GetCertificateAction $getCertificate,
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        CertificateUploadData $data,
        bool $replace = false,
    ): CertificateData {
        $previous = $replace ? $this->credentials->activeForCurrentTenant() : null;
        $previousFingerprint = $previous?->fingerprint_sha256;
        $action = $replace ? 'tenant_credential.replace' : 'tenant_credential.activate';

        try {
            $binary = file_get_contents($data->filePath);
            if ($binary === false) {
                throw new RuntimeException('Falha ao ler arquivo PFX.');
            }

            if (($this->getConsentStatus)()->requiresConsent()) {
                $this->consents->grant(true, $data->actorUserId);
            }

            $password = $data->takePassword();
            $credential = $replace
                ? $this->credentials->replace($binary, $password, $data->actorUserId)
                : $this->credentials->activate($binary, $password, $data->actorUserId);
            unset($binary, $password);
        } catch (RuntimeException $error) {
            $stillActive = $replace
                ? $this->credentials->activeForCurrentTenant()
                : null;
            $previousPreserved = $replace
                && $stillActive !== null
                && $stillActive->fingerprint_sha256 === $previousFingerprint;

            $this->audit->record($action, 'FAILED', $stillActive, [
                'message' => $error->getMessage() ?: 'Falha ao processar certificado.',
                'previous_fingerprint_sha256' => $previousFingerprint,
                'previous_still_active' => $previousPreserved,
            ], $data->actorUserId);

            throw TenantSettingsApiException::certificateMutationFailed(
                $error->getMessage() ?: 'Falha ao processar certificado.',
                $replace,
            );
        } catch (Throwable $error) {
            report($error);
            $this->audit->record($action, 'FAILED', null, [
                'message' => 'Falha ao processar certificado.',
            ], $data->actorUserId);

            throw TenantSettingsApiException::certificateMutationFailed(
                'Falha ao processar certificado.',
                $replace,
            );
        }

        $context = [
            'fingerprint_sha256' => $credential->fingerprint_sha256,
            'holder_cnpj' => $credential->holder_cnpj,
            'valid_to' => $credential->valid_to?->toIso8601String(),
        ];
        if ($replace) {
            $context['previous_fingerprint_sha256'] = $previousFingerprint;
        } else {
            $context['credential_type'] = 'CERTIFICATE';
        }
        $this->audit->record($action, 'SUCCESS', $credential, $context, $data->actorUserId);

        $tenant = $this->currentTenant->tenant();
        $onboarding = $this->actionableStatus->forTenant($tenant)['onboarding'] ?? null;

        return $this->getCertificate->fromCredential($credential, $onboarding);
    }
}
