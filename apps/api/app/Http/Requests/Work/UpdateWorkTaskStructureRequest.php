<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\TaskStructureData;
use App\Models\User;
use App\Models\WorkTask;

final class UpdateWorkTaskStructureRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $task = $this->route('task');
        $process = $task instanceof WorkTask
            ? $task->process()->first()
            : null;

        return $actor instanceof User
            && $process !== null
            && $actor->can('update', $process);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'work_department_id' => ['nullable', 'integer'],
            'assignee_membership_id' => ['nullable', 'integer'],
            'is_required' => ['sometimes', 'boolean'],
            'is_critical' => ['sometimes', 'boolean'],
            'requires_evidence' => ['sometimes', 'boolean'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function structure(): TaskStructureData
    {
        $validated = $this->validated();
        $lockVersion = (int) $validated['lock_version'];
        unset($validated['lock_version']);

        return new TaskStructureData(
            attributes: $validated,
            lockVersion: $lockVersion,
        );
    }
}
