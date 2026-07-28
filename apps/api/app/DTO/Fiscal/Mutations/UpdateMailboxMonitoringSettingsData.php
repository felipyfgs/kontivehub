<?php

namespace App\DTO\Fiscal\Mutations;

use App\Enums\MailboxMonitoringMode;

final readonly class UpdateMailboxMonitoringSettingsData
{
    public function __construct(
        public ?bool $enabled,
        public ?MailboxMonitoringMode $mode,
        public ?string $dailyTime,
        public ?string $timezone,
        public ?int $reconciliationDays,
        public ?int $autoDetailLimit,
        public ?int $monthlyBudgetMicros,
        public bool $monthlyBudgetMicrosProvided,
    ) {}

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        $attributes = [];

        if ($this->enabled !== null) {
            $attributes['enabled'] = $this->enabled;
        }
        if ($this->mode !== null) {
            $attributes['mode'] = $this->mode;
        }
        if ($this->dailyTime !== null) {
            $attributes['daily_time'] = $this->dailyTime;
        }
        if ($this->timezone !== null) {
            $attributes['timezone'] = $this->timezone;
        }
        if ($this->reconciliationDays !== null) {
            $attributes['reconciliation_days'] = $this->reconciliationDays;
        }
        if ($this->autoDetailLimit !== null) {
            $attributes['auto_detail_limit'] = $this->autoDetailLimit;
        }
        if ($this->monthlyBudgetMicrosProvided) {
            $attributes['monthly_budget_micros'] = $this->monthlyBudgetMicros;
        }

        return $attributes;
    }
}
