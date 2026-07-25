<?php

namespace App\Services\Integra;

use App\Enums\OfficeSerproOnboardingStatus;
use App\Enums\SerproEnvironment;
use App\Models\Office;
use App\Models\OfficeSerproOnboardingState;
use App\Services\Serpro\SerproHealthService;

/**
 * Separa diagnóstico global (plataforma) de pendência acionável do escritório (F-3.2).
 */
final class SerproTenantActionableStatusService
{
    public function __construct(
        private readonly OfficeSerproOnboardingService $onboarding,
        private readonly TenantIntegraHealthService $tenantHealth,
        private readonly SerproHealthService $platformHealth,
    ) {}

    /**
     * @return array{
     *   onboarding: array<string, mixed>,
     *   actionable: list<array{code: string, message: string}>,
     *   platform_available: bool,
     *   correlation_id: ?string
     * }
     */
    public function forOffice(Office $office, ?SerproEnvironment $environment = null): array
    {
        $env = $environment ?? SerproEnvironment::from(
            (string) config('serpro.default_environment', 'TRIAL'),
        );

        $state = $this->onboarding->getOrCreateState($office, $env);
        $prereq = $this->onboarding->evaluatePrerequisites($office, $env);
        $health = $this->tenantHealth->forEnvironment($env);

        $actionable = [];
        if ($state->actionable_code !== null) {
            $actionable[] = [
                'code' => $state->actionable_code,
                'message' => (string) $state->actionable_message,
            ];
        } elseif (! $prereq['complete'] && $prereq['missing_code'] !== null) {
            $actionable[] = [
                'code' => $prereq['missing_code'],
                'message' => (string) $prereq['missing_message'],
            ];
        }

        if ($state->status === OfficeSerproOnboardingStatus::TechnicalError) {
            // Não vazar OAuth/mTLS — só estado acionável genérico + correlation
            $actionable = [[
                'code' => 'PLATFORM_UNAVAILABLE',
                'message' => 'Integração SERPRO temporariamente indisponível. Tente novamente mais tarde.',
            ]];
        }

        $platformAvailable = (bool) ($health['available'] ?? false)
            && ! (bool) ($health['kill_switch'] ?? false)
            && ! (bool) ($health['circuit_open'] ?? false);

        return [
            'onboarding' => $state->toTenantArray(),
            'actionable' => $actionable,
            'platform_available' => $platformAvailable,
            'correlation_id' => $state->correlation_id,
            'prerequisites' => [
                'profile' => $prereq['profile'],
                'consent' => $prereq['consent'],
                'a1' => $prereq['a1'],
                'author' => $prereq['author'],
                'complete' => $prereq['complete'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function platformDiagnosis(?SerproEnvironment $environment = null): array
    {
        $env = $environment ?? SerproEnvironment::from(
            (string) config('serpro.default_environment', 'TRIAL'),
        );

        $global = $this->platformHealth->globalHealth($env);

        // Sanitizar — sem secrets; incluir sinais técnicos para ops
        return [
            'environment' => $env->value,
            'kill_switch' => $global['kill_switch'] ?? null,
            'circuit_breaker' => $global['circuit_breaker'] ?? null,
            'active_contract' => $global['active_contract'] ?? null,
            'smoke_status' => $global['smoke_status'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forOnboardingState(OfficeSerproOnboardingState $state): array
    {
        return [
            'tenant' => $state->toTenantArray(),
            'platform' => $state->toPlatformArray(),
        ];
    }
}
