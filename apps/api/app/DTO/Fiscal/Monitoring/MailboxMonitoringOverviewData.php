<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class MailboxMonitoringOverviewData
{
    /**
     * @param  array{initialized_clients:int,pending_clients:int,blocked_clients:int,failed_clients:int}  $coverage
     */
    public function __construct(
        public bool $enabled,
        public bool $runtimeEnabled,
        public string $mode,
        public string $dailyTime,
        public string $timezone,
        public int $reconciliationDays,
        public int $autoDetailLimit,
        public ?int $monthlyBudgetMicros,
        public array $coverage,
        public mixed $lastFreeCheckAt,
        public ?string $lastPaidCheckAt,
        public ?string $lastFullReconciliationAt,
        public ?string $lastDispatchedAt,
        public ?string $nextDueAt,
    ) {}
}
