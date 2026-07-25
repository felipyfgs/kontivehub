<?php

namespace App\Services\Serpro;

use App\Enums\SerproEnvironment;

final class SerproHealthService
{
    public function __construct(
        private readonly SerproContractService $contracts,
        private readonly SerproKillSwitchService $killSwitch,
        private readonly SerproCircuitBreaker $breaker,
    ) {}

    /**
     * Saúde global sanitizada (PLATFORM_ADMIN).
     *
     * @return array<string, mixed>
     */
    public function globalHealth(?SerproEnvironment $environment = null): array
    {
        $env = $environment ?? SerproEnvironment::tryFrom((string) config('serpro.default_environment', 'TRIAL'))
            ?? SerproEnvironment::Trial;

        $active = $this->contracts->activeFor($env);
        $all = $this->contracts->listSanitized($env);

        return [
            'environment' => $env->value,
            'kill_switch' => $this->killSwitch->status(),
            'circuit_breaker' => $this->breaker->globalStatus(),
            'active_contract' => $active?->toSanitizedArray(),
            'contracts' => $all,
            'smoke_status' => config('serpro.smoke.status', 'PENDING_OPS'),
        ];
    }

    /**
     * Saúde tenant-scoped — sem detalhes comerciais/secretos do contrato.
     *
     * @return array<string, mixed>
     */
    public function tenantHealth(SerproEnvironment $environment): array
    {
        $active = $this->contracts->activeFor($environment);
        $ks = $this->killSwitch->isGlobalActive();

        if ($active === null) {
            return [
                'environment' => $environment->value,
                'available' => false,
                'status' => 'UNAVAILABLE',
                'kill_switch' => $ks,
            ];
        }

        $base = $active->toTenantHealthArray();
        $base['kill_switch'] = $ks;
        $base['circuit_open'] = ! $this->breaker->isCallAllowed();

        return $base;
    }
}
