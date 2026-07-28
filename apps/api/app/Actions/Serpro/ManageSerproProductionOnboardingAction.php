<?php

namespace App\Actions\Serpro;

use App\DTO\Serpro\ProductionOnboardingInput;
use App\DTO\Serpro\SerproProductionOnboardingEnvelopeData;
use App\Enums\TenantPermission;
use App\Exceptions\SerproProductionOnboardingApiException;
use App\Models\SerproProductionOnboarding;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Serpro\SerproProductionOnboardingService;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use RuntimeException;
use Throwable;

final readonly class ManageSerproProductionOnboardingAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantAuthorization $tenantAuthorization,
        private SerproProductionOnboardingService $onboarding,
    ) {}

    public function show(User $actor): SerproProductionOnboardingEnvelopeData
    {
        $tenant = $this->resolveTenant($actor);

        return $this->envelope($tenant);
    }

    public function activate(
        User $actor,
        ProductionOnboardingInput $input,
    ): SerproProductionOnboardingEnvelopeData {
        $tenant = $this->resolveTenant($actor);

        if (! $this->tenantAuthorization->allows($actor, TenantPermission::CredentialsManage)) {
            throw SerproProductionOnboardingApiException::permissionDenied();
        }

        if (! FeatureFlags::isSerproProductionOnboardingEnabled($tenant->id)) {
            throw SerproProductionOnboardingApiException::featureDisabled();
        }

        try {
            $state = $this->onboarding->activate($tenant, $actor, $input);
        } catch (RuntimeException $error) {
            throw SerproProductionOnboardingApiException::activationFailed($error);
        } catch (Throwable $error) {
            report($error);

            throw SerproProductionOnboardingApiException::unexpectedFailure();
        }

        return $this->envelope($tenant, $state);
    }

    private function resolveTenant(User $actor): Tenant
    {
        return $this->currentTenant->resolve($actor)
            ?? throw SerproProductionOnboardingApiException::tenantRequired();
    }

    private function envelope(
        Tenant $tenant,
        ?SerproProductionOnboarding $state = null,
    ): SerproProductionOnboardingEnvelopeData {
        $text = (string) config('serpro.production_onboarding.consent_text', '');

        return new SerproProductionOnboardingEnvelopeData(
            enabled: FeatureFlags::isSerproProductionOnboardingEnabled($tenant->id),
            tenantId: $tenant->id,
            consent: [
                'version' => (string) config(
                    'serpro.production_onboarding.consent_version',
                    'serpro-prod-onboarding.v1',
                ),
                'text' => $text,
                'text_sha256' => hash('sha256', $text),
            ],
            onboarding: $state ?? $this->onboarding->latestForTenant($tenant),
        );
    }
}
