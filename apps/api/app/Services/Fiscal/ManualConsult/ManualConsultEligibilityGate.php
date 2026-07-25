<?php

namespace App\Services\Fiscal\ManualConsult;

use App\Enums\ManualConsultEligibility;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproCapabilityDriver;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
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
        Office $office,
        ManualConsultActionDefinition $def,
        ?Client $client = null,
    ): ManualConsultEligibility {
        $environment = $this->environment();
        $auth = $this->authorizationFor($office, $environment);

        return $this->evaluateWithContext(
            $office,
            $def,
            $this->hasUsableToken($auth),
            $client,
            $auth,
            $environment,
        );
    }

    public function evaluateWithContext(
        Office $office,
        ManualConsultActionDefinition $def,
        bool $hasToken,
        ?Client $client,
        ?OfficeSerproAuthorization $auth,
        SerproEnvironment $environment,
    ): ManualConsultEligibility {
        if (! $def->hasHandler) {
            return ManualConsultEligibility::AdapterMissing;
        }

        if ($def->featureModule !== null
            && ! FeatureFlags::isModuleEnabled($def->featureModule, $office->id)
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
            if (! $this->hasAnyRequiredPower($office, $client, $def->requiredProxyPowers, $auth, $environment)) {
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

    public function authorizationFor(Office $office, SerproEnvironment $environment): ?OfficeSerproAuthorization
    {
        return OfficeSerproAuthorization::query()
            ->where('office_id', $office->id)
            ->where('environment', $environment->value)
            ->first();
    }

    public function hasUsableToken(?OfficeSerproAuthorization $auth): bool
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
        Office $office,
        Client $client,
        array $powers,
        ?OfficeSerproAuthorization $auth,
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
                officeId: $office->id,
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
