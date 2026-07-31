<?php

namespace App\DTO\Work;

use App\Models\WorkProcess;
use App\Models\WorkTask;

final readonly class ProcessTaskViewData
{
    public function __construct(
        public WorkTask $task,
        public WorkProcess $process,
        public string $today,
    ) {}
}
