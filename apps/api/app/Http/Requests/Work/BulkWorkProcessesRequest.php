<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\WorkProcessBulkData;
use App\Models\User;
use App\Models\WorkProcess;

final class BulkWorkProcessesRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->can('bulk', WorkProcess::class);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'integer'],
            'items.*.lock_version' => ['required', 'integer', 'min:1'],
            'changes' => ['required', 'array'],
            'changes.action' => ['required', 'string', 'in:archive,assign,set_department,set_due_date'],
            'changes.assignee_membership_id' => ['sometimes', 'nullable', 'integer'],
            'changes.work_department_id' => ['sometimes', 'nullable', 'integer'],
            'changes.due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function bulk(): WorkProcessBulkData
    {
        $validated = $this->validated();

        return new WorkProcessBulkData(
            $validated['items'],
            $validated['changes'],
        );
    }
}
