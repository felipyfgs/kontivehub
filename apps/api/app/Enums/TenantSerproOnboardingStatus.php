<?php

namespace App\Enums;

/**
 * State machine do onboarding de autorização SERPRO do escritório.
 * Derivado de perfil + consentimento + certificado (jobs em waves posteriores).
 */
enum TenantSerproOnboardingStatus: string
{
    case Incomplete = 'incomplete';
    case Configuring = 'configuring';
    case Validating = 'validating';
    case Authorizing = 'authorizing';
    case LoadingProxyPowers = 'loading_proxy_powers';
    case Syncing = 'syncing';
    case Ready = 'ready';
    case Provisioning = 'provisioning';
    case Authorized = 'authorized';
    case ActionRequired = 'action_required';
    case TechnicalError = 'technical_error';
    case Revoked = 'revoked';

    public function isTerminal(): bool
    {
        return $this === self::Revoked;
    }

    public function allowsExternalCalls(): bool
    {
        return in_array($this, [self::Authorized, self::Ready], true);
    }
}
