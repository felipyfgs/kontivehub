<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\ProcessGroupFiltersData;
use App\Enums\Work\ProcessStatus;
use App\Models\User;
use App\Models\WorkProcess;
use App\Services\Work\ProcessGroupQuery;
use Illuminate\Validation\Rule;

final class ListWorkProcessGroupsRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->can('viewAny', WorkProcess::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'group_by' => ['required', 'string', Rule::in(['client', 'routine'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'competence' => ['sometimes', 'nullable', 'string', 'max:16'],
            'status' => ['sometimes', 'nullable', 'string', Rule::enum(ProcessStatus::class)],
            'client_id' => ['sometimes', 'nullable', 'integer'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'assignee_membership_id' => ['sometimes', 'nullable', 'integer'],
            'include_archived' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'sort' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(ProcessGroupQuery::SORT_WHITELIST),
            ],
            'direction' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['asc', 'desc']),
            ],
        ];
    }

    public function filters(): ProcessGroupFiltersData
    {
        $validated = $this->validated();
        $validated['include_archived'] = $this->boolean('include_archived');

        return new ProcessGroupFiltersData($validated);
    }
}
