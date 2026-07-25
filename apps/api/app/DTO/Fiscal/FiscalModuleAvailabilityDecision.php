<?php

namespace App\DTO\Fiscal;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleAvailabilityState;
use App\Enums\FiscalOperationClass;
use App\Enums\FiscalProfile;

final readonly class FiscalModuleAvailabilityDecision
{
    public function __construct(
        public FiscalControlModule $module,
        public FiscalProfile $profile,
        public FiscalOperationClass $operationClass,
        public bool $allowed,
        public FiscalModuleAvailabilityState $state,
        public ?string $reasonCode = null,
        public ?string $reason = null,
        public ?int $controlId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'module_key' => $this->module->value,
            'label' => $this->module->label(),
            'profile' => $this->profile->value,
            'operation_class' => $this->operationClass->value,
            'allowed' => $this->allowed,
            'state' => $this->state->value,
            'reason_code' => $this->reasonCode,
            'reason' => $this->reason,
            'control_id' => $this->controlId,
            'historical_data_visible' => true,
        ];
    }
}
