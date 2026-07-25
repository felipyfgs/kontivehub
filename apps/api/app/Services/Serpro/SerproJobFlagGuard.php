<?php

namespace App\Services\Serpro;

use App\Enums\SerproCapabilityDriver;
use App\Support\FeatureFlags;
use RuntimeException;

/**
 * Flags no dispatch e novamente no handle do job (fail-closed).
 * Usa capability driver SERPRO + kill switches; não confia só no dispatch.
 */
final class SerproJobFlagGuard
{
    public function __construct(
        private readonly CapabilityDriverResolver $drivers,
        private readonly SerproKillSwitchService $killSwitch,
    ) {}

    /**
     * @return array{allowed: bool, code: string|null, message: string|null, capability: string|null, driver: string|null}
     */
    public function assertAllowed(string $jobShortName, ?int $officeId = null): array
    {
        /** @var array<string, string> $map */
        $map = config('serpro.jobs.flag_capabilities', []);
        $capability = $map[$jobShortName] ?? null;

        if ($this->killSwitch->isGlobalActive()) {
            return [
                'allowed' => false,
                'code' => 'KILL_SWITCH',
                'message' => 'Kill switch global SERPRO ativo.',
                'capability' => $capability,
                'driver' => null,
            ];
        }

        if (FeatureFlags::isKillSwitchActive()) {
            return [
                'allowed' => false,
                'code' => 'FEATURES_KILL_SWITCH',
                'message' => 'Feature kill switch global ativo.',
                'capability' => $capability,
                'driver' => null,
            ];
        }

        if ($capability === null) {
            // Job sem capability mapeada: só kill switches
            return [
                'allowed' => true,
                'code' => null,
                'message' => null,
                'capability' => null,
                'driver' => null,
            ];
        }

        $driver = $this->drivers->forCapability($capability);
        if ($driver === SerproCapabilityDriver::Disabled) {
            return [
                'allowed' => false,
                'code' => 'CAPABILITY_DISABLED',
                'message' => "Capability {$capability} desabilitada.",
                'capability' => $capability,
                'driver' => $driver->value,
            ];
        }

        return [
            'allowed' => true,
            'code' => null,
            'message' => null,
            'capability' => $capability,
            'driver' => $driver->value,
        ];
    }

    public function assertOrThrow(string $jobShortName, ?int $officeId = null): void
    {
        $check = $this->assertAllowed($jobShortName, $officeId);
        if (! $check['allowed']) {
            throw new RuntimeException(($check['code'] ?? 'FLAG_BLOCKED').': '.($check['message'] ?? 'bloqueado'));
        }
    }
}
