<?php

namespace App\Services\MeiAutomation;

use App\Enums\MeiProvider;
use App\Models\Office;

final class MeiProviderPolicy
{
    /** @return list<MeiProvider> */
    public function providers(Office $office, string $operationKey): array
    {
        if (! $this->portalEnabledFor($office)) {
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

    public function portalEnabledFor(Office $office): bool
    {
        if (! (bool) config('mei_automation.enabled', false)
            || (bool) config('mei_automation.kill_switch', false)
            || (! (bool) config('mei_automation.live_egress_enabled', false)
                && ! (bool) config('mei_automation.fixture_enabled', false))) {
            return false;
        }

        if ((bool) config('mei_automation.allow_all_offices', false)) {
            return true;
        }

        return in_array((int) $office->id, (array) config('mei_automation.office_allowlist', []), true);
    }
}
