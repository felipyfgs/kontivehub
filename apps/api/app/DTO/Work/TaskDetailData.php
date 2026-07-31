<?php

namespace App\DTO\Work;

use App\Models\WorkTask;

final readonly class TaskDetailData
{
    /**
     * @param  list<string>  $risks
     */
    public function __construct(
        public WorkTask $task,
        public array $risks,
        public ?string $effectiveDueDate,
        public string $bucket,
    ) {}
}
