<?php

namespace App\Services\Fiscal\ManualConsult;

use App\Enums\ManualConsultEligibility;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproCapabilityDriver;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\TenantSerproAuthorization;
use App\Services\Integra\TaxProxyPowerService;
use App\Services\Serpro\CapabilityDriverResolver;
use App\Support\FeatureFlags;

/**
 * Preflight compartilhado inventário GET × execução POST (sem chamar SERPRO).
 */
final class ManualConsultEligibilityGate
{
    public function __construct(
        private readonly CapabilityDriverResolver $capabilities,
        private readonly TaxProxyPowerService $proxyPowers,
    ) {}

    public function evaluate(
        Tenant $tenant,
        ManualConsultActionDefinition $def,
        ?Client $client = null,
    ): ManualConsultEligibility {
        $environment = $this->environment();
        $auth = $this->authorizationFor($tenant, $environment);

        return $this->evaluateWithContext(
            $tenant,
            $def,
            $this->hasUsableToken($auth),
            $client,
            $auth,
            $environment,
        );
    }

    public function evaluateWithContext(
        Tenant $tenant,
        ManualConsultActionDefinition $def,
        bool $hasToken,
        ?Client $client,
        ?TenantSerproAuthorization $auth,
        SerproEnvironment $environment,
    ): ManualConsultEligibility {
        if (! $def->hasHandler) {
            return ManualConsultEligibility::AdapterMissing;
        }

        if ($def->featureModule !== null
            && ! FeatureFlags::isModuleEnabled($def->featureModule, $tenant->id)
        ) {
            return ManualConsultEligibility::ModuleOff;
        }

        if (FeatureFlags::isKillSwitchActive() || (bool) config('serpro.kill_switch', false)) {
            return ManualConsultEligibility::CapabilityOff;
        }

        try {
            $driver = $this->capabilities->forOperationKey($def->operationKey);
        } catch (\Throwable) {
            return ManualConsultEligibility::CapabilityOff;
        }
        if ($driver === SerproCapabilityDriver::Disabled) {
            return ManualConsultEligibility::CapabilityOff;
        }

        // O Trial oficial usa identidades e payloads fixos da documentação;
        // não depende de token de procurador nem de poder e-CAC do cliente.
        if ($environment === SerproEnvironment::Trial) {
            return ManualConsultEligibility::Ready;
        }

        if (! $hasToken) {
            return ManualConsultEligibility::TokenMissing;
        }

        if ($client !== null && $def->requiredProxyPowers !== []) {
            if (! $this->hasAnyRequiredPower($tenant, $client, $def->requiredProxyPowers, $auth, $environment)) {
                return ManualConsultEligibility::PowerMissing;
            }
        }

        return ManualConsultEligibility::Ready;
    }

    public function environment(): SerproEnvironment
    {
        $raw = (string) config('serpro.default_environment', 'TRIAL');

        return SerproEnvironment::tryFrom(strtoupper($raw)) ?? SerproEnvironment::Trial;
    }

    public function authorizationFor(Tenant $tenant, SerproEnvironment $environment): ?TenantSerproAuthorization
    {
        return TenantSerproAuthorization::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment', $environment->value)
            ->first();
    }

    public function hasUsableToken(?TenantSerproAuthorization $auth): bool
    {
        if ($auth === null) {
            return false;
        }
        if ($auth->status !== SerproAuthorizationStatus::TokenActive) {
            return false;
        }

        return $auth->procurador_token_vault_object_id !== null
            && $auth->procurador_token_expires_at !== null
            && $auth->procurador_token_expires_at->isFuture();
    }

    /**
     * @param  list<string>  $powers
     */
    public function hasAnyRequiredPower(
        Tenant $tenant,
        Client $client,
        array $powers,
        ?TenantSerproAuthorization $auth,
        SerproEnvironment $environment,
    ): bool {
        if ($powers === []) {
            return true;
        }

        $author = (string) ($auth?->author_identity ?? '');
        if ($author === '' || $author === '00000000000000') {
            return false;
        }

        foreach ($powers as $code) {
            $usable = $this->proxyPowers->findUsablePower(
                tenantId: $tenant->id,
                clientId: $client->id,
                powerCode: strtoupper($code),
                authorIdentity: $author,
                environment: $environment,
                requireD1: false,
                requireFresh: true,
                requireAccept: true,
            );
            if ($usable !== null) {
                return true;
            }
        }

        return false;
    }
}
