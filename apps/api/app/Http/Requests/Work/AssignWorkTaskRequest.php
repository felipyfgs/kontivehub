<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\TaskAssignmentData;
use App\Models\User;
use App\Models\WorkTask;

final class AssignWorkTaskRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $task = $this->route('task');

        return $actor instanceof User
            && $task instanceof WorkTask
            && $actor->can('assign', $task);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'assignee_membership_id' => ['sometimes', 'nullable', 'integer'],
            'work_department_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function assignment(): TaskAssignmentData
    {
        $validated = $this->validated();
        $attributes = [];
        foreach (['assignee_membership_id', 'work_department_id'] as $key) {
            if (array_key_exists($key, $validated)) {
                $attributes[$key] = $validated[$key] === null
                    ? null
                    : (int) $validated[$key];
            }
        }

        return new TaskAssignmentData(
            lockVersion: (int) $validated['lock_version'],
            attributes: $attributes,
        );
    }
}
