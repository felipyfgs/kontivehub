<?php

namespace App\Actions\Work;

use App\Domain\Work\DueDateCalculator;
use App\Domain\Work\QueueBucketResolver;
use App\Domain\Work\WorkRiskCalculator;
use App\DTO\Work\WorkTaskDetailData;
use App\Models\WorkTask;
use App\Support\CurrentTenant;
use App\Support\Work\TenantTimezone;

final readonly class ShowWorkTaskAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private WorkRiskCalculator $risks = new WorkRiskCalculator,
        private QueueBucketResolver $buckets = new QueueBucketResolver,
        private DueDateCalculator $dates = new DueDateCalculator,
    ) {}

    public function execute(WorkTask $task): WorkTaskDetailData
    {
        $task->load([
            'process.client',
            'department',
            'assigneeMembership.user',
            'evidences',
            'comments',
        ]);

        $today = $this->dates->todayInTenant(
            TenantTimezone::for($this->currentTenant->tenant()),
        );
        $process = $task->process;
        $effectiveDueDate = $this->risks->effectiveDueDate(
            $task->due_date?->format('Y-m-d'),
            $process?->target_due_date?->format('Y-m-d'),
            $process?->due_date?->format('Y-m-d'),
        );
        $riskList = $this->risks->forTask(
            $task->status,
            $task->due_date?->format('Y-m-d'),
            $process?->target_due_date?->format('Y-m-d'),
            $process?->due_date?->format('Y-m-d'),
            (bool) ($process?->subject_to_fine),
            $task->assignee_membership_id,
            $today,
        );

        return new WorkTaskDetailData(
            task: $task,
            risks: array_map(
                static fn ($risk): string => $risk->value,
                $riskList,
            ),
            effectiveDueDate: $effectiveDueDate,
            bucket: $this->buckets
                ->resolve($task->status, $riskList, $effectiveDueDate, $today)
                ->value,
        );
    }
}
