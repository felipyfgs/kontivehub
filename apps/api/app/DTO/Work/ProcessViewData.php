<?php

namespace App\DTO\Work;

use App\Models\WorkProcess;

final readonly class ProcessViewData
{
    /** @param array{module_key: string, label: string, href: string}|null $monitoringContext */
    public function __construct(
        public WorkProcess $process,
        public string $today,
        public bool $detailed,
        public bool $includeTasks,
        public ?array $monitoringContext,
    ) {}
}
