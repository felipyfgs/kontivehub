<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\TenantIntegrationRefreshData;
use App\Enums\FiscalProfile;
use App\Exceptions\TenantSettingsApiException;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\TenantCredentialService;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Services\Integra\TenantSerproOnboardingService;
use App\Support\CurrentTenant;
use RuntimeException;

final readonly class RefreshTenantIntegrationAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantCredentialService $credentials,
        private TenantSerproOnboardingService $onboarding,
        private TenantSerproAuthorizationService $authorizations,
        private AuditLogger $audit,
    ) {}

    public function __invoke(int $actorUserId): TenantIntegrationRefreshData
    {
        $tenant = $this->currentTenant->tenant();
        $environment = FiscalProfile::configured()->serproEnvironment();

        if ($this->credentials->activeForCurrentTenant() === null) {
            throw TenantSettingsApiException::integrationCertificateRequired();
        }

        try {
            $this->onboarding->ensureAuthorFromCertificate(
                $tenant,
                $environment,
                $actorUserId,
            );
        } catch (RuntimeException $error) {
            $this->audit->record('tenant.integration.refresh', 'FAILED', null, [
                'message' => $error->getMessage(),
                'environment' => $environment->value,
                'stage' => 'ensure_author',
            ], $actorUserId, $tenant->id);

            throw TenantSettingsApiException::integrationRefreshFailed(
                $error->getMessage(),
            );
        }

        $authorization = null;
        $refreshError = null;
        try {
            $authorization = $this->authorizations->refreshProcuradorToken(
                $tenant,
                $environment,
                $actorUserId,
                force: true,
            );
        } catch (RuntimeException $error) {
            $refreshError = $error;
        }

        try {
            $this->onboarding->evaluateAndMaybeEnqueue(
                $tenant,
                $environment,
                $actorUserId,
                force: true,
            );
        } catch (RuntimeException $error) {
            $this->audit->record('tenant.integration.refresh', 'FAILED', null, [
                'message' => $error->getMessage(),
                'environment' => $environment->value,
                'stage' => 'onboarding',
                'refresh_error' => $refreshError?->getMessage(),
            ], $actorUserId, $tenant->id);

            throw TenantSettingsApiException::integrationRefreshFailed(
                $error->getMessage(),
            );
        }

        $authorization ??= $this->authorizations->getOrCreate($tenant, $environment);
        $result = new TenantIntegrationRefreshData(
            status: $authorization->status->value,
            procuradorTokenExpiresAt: $authorization->procurador_token_expires_at?->toIso8601String(),
            hasProcuradorToken: $authorization->procurador_token_vault_object_id !== null,
            onboardingEvaluated: true,
        );

        if ($refreshError !== null) {
            $this->audit->record('tenant.integration.refresh', 'PARTIAL', $authorization, [
                'environment' => $environment->value,
                'status' => $authorization->status->value,
                'message' => $refreshError->getMessage(),
                'onboarding_evaluated' => true,
            ], $actorUserId, $tenant->id);

            throw TenantSettingsApiException::integrationRefreshFailed(
                $refreshError->getMessage(),
                [
                    'data' => [
                        'status' => $result->status,
                        'procurador_token_expires_at' => $result->procuradorTokenExpiresAt,
                        'has_procurador_token' => $result->hasProcuradorToken,
                        'onboarding_evaluated' => true,
                    ],
                ],
            );
        }

        $this->audit->record('tenant.integration.refresh', 'SUCCESS', $authorization, [
            'environment' => $environment->value,
            'status' => $authorization->status->value,
        ], $actorUserId, $tenant->id);

        return $result;
    }
}
