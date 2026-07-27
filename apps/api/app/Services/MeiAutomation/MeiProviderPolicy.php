<?php

namespace App\Services\MeiAutomation;

use App\Enums\MeiProvider;
use App\Models\Tenant;

final class MeiProviderPolicy
{
    /** @return list<MeiProvider> */
    public function providers(Tenant $tenant, string $operationKey): array
    {
        if (! $this->portalEnabledFor($tenant)) {
            return [MeiProvider::Serpro];
        }

        $operations = (array) config('mei_automation.provider_policy.operations', []);
        $rawMode = $operations[$operationKey] ?? null;
        $mode = is_string($rawMode) && trim($rawMode) !== ''
            ? strtolower(trim($rawMode))
            : strtolower((string) config('mei_automation.provider_policy.default', 'serpro'));

        return match ($mode) {
            'portal' => [MeiProvider::ReceitaPortal],
            'portal_then_serpro' => [MeiProvider::ReceitaPortal, MeiProvider::Serpro],
            default => [MeiProvider::Serpro],
        };
    }

    public function portalEnabledFor(Tenant $tenant): bool
    {
        if (! (bool) config('mei_automation.enabled', false)
            || (bool) config('mei_automation.kill_switch', false)
            || (! (bool) config('mei_automation.live_egress_enabled', false)
                && ! (bool) config('mei_automation.fixture_enabled', false))) {
            return false;
        }

        if ((bool) config('mei_automation.allow_all_tenants', false)) {
            return true;
        }

        return in_array((int) $tenant->id, (array) config('mei_automation.tenant_allowlist', []), true);
    }
}
