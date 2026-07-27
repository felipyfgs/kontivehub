<?php

namespace App\Services\Fiscal\Availability;

use App\DTO\Fiscal\FiscalModuleAvailabilityDecision;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleAvailabilityState;
use App\Enums\FiscalModuleControlScope;
use App\Enums\FiscalOperationClass;
use App\Enums\FiscalProfile;
use App\Enums\TenantSerproOnboardingStatus;
use App\Exceptions\FiscalModuleUnavailableException;
use App\Models\FiscalModuleControl;
use App\Models\Tenant;
use App\Models\TenantSerproOnboardingState;

final class FiscalModuleAvailabilityService
{
    public function resolve(
        FiscalControlModule|string $module,
        ?Tenant $tenant = null,
        FiscalOperationClass $operationClass = FiscalOperationClass::Read,
        bool $officialTrialScenario = true,
        bool $eligible = true,
    ): FiscalModuleAvailabilityDecision {
        $module = is_string($module) ? FiscalControlModule::fromRuntimeKey($module) : $module;
        $profile = FiscalProfile::configured();

        if ((bool) config('fiscal.kill_switch', false)) {
            return $this->deny($module, $profile, $operationClass, FiscalModuleAvailabilityState::TechnicalFailure, 'KILL_SWITCH', 'Consultas fiscais pausadas pela plataforma.');
        }

        $global = $this->restrictedControl($module, FiscalModuleControlScope::Global);
        if ($global !== null) {
            return $this->deny($module, $profile, $operationClass, FiscalModuleAvailabilityState::GloballyRestricted, 'GLOBAL_RESTRICTION', $global->reason, $global->id);
        }

        if ($tenant !== null) {
            $local = $this->restrictedControl($module, FiscalModuleControlScope::Tenant, (int) $tenant->id);
            if ($local !== null) {
                return $this->deny($module, $profile, $operationClass, FiscalModuleAvailabilityState::TenantRestricted, 'TENANT_RESTRICTION', $local->reason, $local->id);
            }
        }

        if (! $profile->allows($operationClass, $officialTrialScenario)) {
            $code = $operationClass === FiscalOperationClass::FiscalMutation
                ? 'FISCAL_MUTATION_BLOCKED'
                : 'PROFILE_OPERATION_BLOCKED';

            return $this->deny($module, $profile, $operationClass, FiscalModuleAvailabilityState::TechnicalFailure, $code, 'O perfil fiscal atual não permite esta classe de operação.');
        }

        if ($tenant !== null && ! $this->isTechnicallyReady($tenant, $profile)) {
            return $this->deny($module, $profile, $operationClass, FiscalModuleAvailabilityState::AwaitingConfiguration, 'TENANT_NOT_READY', 'Conclua a configuração fiscal do escritório.');
        }

        if (! $eligible) {
            return $this->deny($module, $profile, $operationClass, FiscalModuleAvailabilityState::AwaitingConfiguration, 'OPERATION_NOT_ELIGIBLE', 'Cliente ou operação ainda não está elegível.');
        }

        return new FiscalModuleAvailabilityDecision(
            module: $module,
            profile: $profile,
            operationClass: $operationClass,
            allowed: true,
            state: FiscalModuleAvailabilityState::Available,
        );
    }

    public function assertExecutionAllowed(
        FiscalControlModule|string $module,
        Tenant $tenant,
        FiscalOperationClass $operationClass = FiscalOperationClass::Read,
        bool $officialTrialScenario = true,
        bool $eligible = true,
    ): FiscalModuleAvailabilityDecision {
        $decision = $this->resolve($module, $tenant, $operationClass, $officialTrialScenario, $eligible);
        if (! $decision->allowed) {
            throw new FiscalModuleUnavailableException($decision);
        }

        return $decision;
    }

    private function restrictedControl(
        FiscalControlModule $module,
        FiscalModuleControlScope $scope,
        ?int $tenantId = null,
    ): ?FiscalModuleControl {
        return FiscalModuleControl::query()
            ->where('control_key', FiscalModuleControl::controlKey($module, $scope, $tenantId))
            ->where('restricted', true)
            ->first();
    }

    private function isTechnicallyReady(Tenant $tenant, FiscalProfile $profile): bool
    {
        if (! $tenant->isOperational()) {
            return false;
        }
        if ($profile === FiscalProfile::Dev) {
            return true;
        }

        return TenantSerproOnboardingState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [
                TenantSerproOnboardingStatus::Ready->value,
                TenantSerproOnboardingStatus::Authorized->value,
            ])
            ->exists();
    }

    private function deny(
        FiscalControlModule $module,
        FiscalProfile $profile,
        FiscalOperationClass $operationClass,
        FiscalModuleAvailabilityState $state,
        string $reasonCode,
        string $reason,
        ?int $controlId = null,
    ): FiscalModuleAvailabilityDecision {
        return new FiscalModuleAvailabilityDecision(
            module: $module,
            profile: $profile,
            operationClass: $operationClass,
            allowed: false,
            state: $state,
            reasonCode: $reasonCode,
            reason: $reason,
            controlId: $controlId,
        );
    }
}
