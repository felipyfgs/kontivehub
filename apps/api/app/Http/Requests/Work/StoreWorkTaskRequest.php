<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\WorkTaskStructureData;
use App\Models\User;
use App\Models\WorkProcess;

final class StoreWorkTaskRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $process = $this->route('process');

        return $actor instanceof User
            && $process instanceof WorkProcess
            && $actor->can('update', $process);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:1'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'work_department_id' => ['nullable', 'integer'],
            'assignee_membership_id' => ['nullable', 'integer'],
            'is_required' => ['sometimes', 'boolean'],
            'is_critical' => ['sometimes', 'boolean'],
            'requires_evidence' => ['sometimes', 'boolean'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function structure(): WorkTaskStructureData
    {
        return new WorkTaskStructureData($this->validated());
    }
}
