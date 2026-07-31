<?php

namespace App\Http\Resources;

use App\Domain\Work\WorkRiskCalculator;
use App\DTO\Work\ProcessTaskViewData;
use App\Enums\Work\WorkRisk;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProcessTaskViewData */
final class WorkProcessTaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ProcessTaskViewData $view */
        $view = $this->resource;
        $task = $view->task;
        $process = $view->process;
        $risks = new WorkRiskCalculator;
        $taskRisks = $risks->forTask(
            $task->status,
            $task->due_date?->format('Y-m-d'),
            $process->target_due_date?->format('Y-m-d'),
            $process->due_date?->format('Y-m-d'),
            (bool) $process->subject_to_fine,
            $task->assignee_membership_id,
            $view->today,
        );

        return [
            'id' => $task->id,
            'sort_order' => $task->sort_order,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'target_due_date' => $task->target_due_date?->format('Y-m-d'),
            'effective_due_date' => $risks->effectiveDueDate(
                $task->due_date?->format('Y-m-d'),
                $process->target_due_date?->format('Y-m-d'),
                $process->due_date?->format('Y-m-d'),
            ),
            'is_required' => $task->is_required,
            'is_critical' => $task->is_critical,
            'requires_evidence' => $task->requires_evidence,
            'block_reason' => $task->block_reason,
            'assignee_membership_id' => $task->assignee_membership_id,
            'work_department_id' => $task->work_department_id,
            'lock_version' => $task->lock_version,
            'risks' => array_map(
                static fn (WorkRisk $risk): string => $risk->value,
                $taskRisks,
            ),
            'department' => $task->relationLoaded('department') && $task->department ? [
                'id' => $task->department->id,
                'name' => $task->department->name,
                'code' => $task->department->code,
            ] : null,
            'assignee' => $task->relationLoaded('assigneeMembership')
                && $task->assigneeMembership?->user ? [
                    'membership_id' => $task->assigneeMembership->id,
                    'name' => $task->assigneeMembership->user->name,
                ] : null,
            'evidence_count' => $task->relationLoaded('evidences')
                ? $task->evidences->count()
                : null,
        ];
    }
}
