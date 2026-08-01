<?php

namespace App\Http\Resources\Work;

use App\Models\WorkTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkTask */
final class TaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkTask $task */
        $task = $this->resource;
        $data = [
            'id' => $task->id,
            'work_process_id' => $task->work_process_id,
            'sort_order' => $task->sort_order,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'is_required' => $task->is_required,
            'is_critical' => $task->is_critical,
            'requires_evidence' => $task->requires_evidence,
            'block_reason' => $task->block_reason,
            'assignee_membership_id' => $task->assignee_membership_id,
            'work_department_id' => $task->work_department_id,
            'lock_version' => $task->lock_version,
            'started_at' => $task->started_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
        ];

        if ($task->relationLoaded('department') && $task->department) {
            $data['department'] = [
                'id' => $task->department->id,
                'name' => $task->department->name,
                'code' => $task->department->code,
            ];
        }
        if ($task->relationLoaded('assigneeMembership') && $task->assigneeMembership?->user) {
            $data['assignee'] = [
                'membership_id' => $task->assigneeMembership->id,
                'name' => $task->assigneeMembership->user->name,
            ];
        }

        return $data;
    }
}
