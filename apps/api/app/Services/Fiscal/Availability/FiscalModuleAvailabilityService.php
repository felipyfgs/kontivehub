<?php

namespace App\Services\Fiscal\Availability;

use App\DTO\Fiscal\FiscalModuleAvailabilityDecision;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleAvailabilityState;
use App\Enums\FiscalModuleControlScope;
use App\Enums\FiscalOperationClass;
use App\Enums\FiscalProfile;
use App\Enums\OfficeSerproOnboardingStatus;
use App\Exceptions\FiscalModuleUnavailableException;
use App\Models\FiscalModuleControl;
use App\Models\Office;
use App\Models\OfficeSerproOnboardingState;
use Illuminate\Support\Facades\Schema;

final class FiscalModuleAvailabilityService
{
    public function resolve(
        FiscalControlModule|string $module,
        ?Office $office = null,
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

        if ($office !== null) {
            $local = $this->restrictedControl($module, FiscalModuleControlScope::Office, (int) $office->id);
            if ($local !== null) {
                return $this->deny($module, $profile, $operationClass, FiscalModuleAvailabilityState::OfficeRestricted, 'OFFICE_RESTRICTION', $local->reason, $local->id);
            }
        }

        if (! $profile->allows($operationClass, $officialTrialScenario)) {
            $code = $operationClass === FiscalOperationClass::FiscalMutation
                ? 'FISCAL_MUTATION_BLOCKED'
                : 'PROFILE_OPERATION_BLOCKED';

            return $this->deny($module, $profile, $operationClass, FiscalModuleAvailabilityState::TechnicalFailure, $code, 'O perfil fiscal atual não permite esta classe de operação.');
        }

        if ($office !== null && ! $this->isTechnicallyReady($office, $profile)) {
            return $this->deny($module, $profile, $operationClass, FiscalModuleAvailabilityState::AwaitingConfiguration, 'OFFICE_NOT_READY', 'Conclua a configuração fiscal do escritório.');
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
        Office $office,
        FiscalOperationClass $operationClass = FiscalOperationClass::Read,
        bool $officialTrialScenario = true,
        bool $eligible = true,
    ): FiscalModuleAvailabilityDecision {
        $decision = $this->resolve($module, $office, $operationClass, $officialTrialScenario, $eligible);
        if (! $decision->allowed) {
            throw new FiscalModuleUnavailableException($decision);
        }

        return $decision;
    }

    private function restrictedControl(
        FiscalControlModule $module,
        FiscalModuleControlScope $scope,
        ?int $officeId = null,
    ): ?FiscalModuleControl {
        // Compatibilidade durante deploy rolling: ausência da tabela equivale a
        // ausência de exceção, conforme o contrato "liberado por padrão".
        if (! Schema::hasTable('fiscal_module_controls')) {
            return null;
        }

        return FiscalModuleControl::query()
            ->where('control_key', FiscalModuleControl::controlKey($module, $scope, $officeId))
            ->where('restricted', true)
            ->first();
    }

    private function isTechnicallyReady(Office $office, FiscalProfile $profile): bool
    {
        if (! $office->isOperational()) {
            return false;
        }
        if ($profile === FiscalProfile::Dev) {
            return true;
        }

        return OfficeSerproOnboardingState::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereIn('status', [
                OfficeSerproOnboardingStatus::Ready->value,
                OfficeSerproOnboardingStatus::Authorized->value,
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
