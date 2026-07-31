<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\TaskBulkData;
use App\Models\User;
use App\Models\WorkTask;

final class BulkWorkTasksRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('bulk', WorkTask::class);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'integer'],
            'items.*.lock_version' => ['required', 'integer', 'min:1'],
            'changes' => ['required', 'array'],
            'changes.action' => ['required', 'string', 'in:start,complete,resume,block,claim,assign,set_due_date,set_department'],
            'changes.assignee_membership_id' => ['sometimes', 'nullable', 'integer'],
            'changes.work_department_id' => ['sometimes', 'nullable', 'integer'],
            'changes.due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'changes.reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'changes.justification' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function bulk(): TaskBulkData
    {
        $validated = $this->validated();

        return new TaskBulkData(
            items: $validated['items'],
            changes: $validated['changes'],
        );
    }
}
