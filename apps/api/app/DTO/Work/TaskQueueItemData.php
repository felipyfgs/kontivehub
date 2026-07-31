<?php

namespace App\DTO\Work;

use App\Models\WorkTask;

final readonly class TaskQueueItemData
{
    /**
     * @param  list<string>  $risks
     */
    public function __construct(
        public WorkTask $task,
        public string $bucket,
        public array $risks,
        public ?string $effectiveDueDate,
    ) {}
}
