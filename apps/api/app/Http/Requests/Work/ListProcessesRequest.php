<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\ProcessFiltersData;
use App\Enums\Work\ProcessStatus;
use App\Models\User;
use App\Models\WorkProcess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ListProcessesRequest extends TenantScopedRequest
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
            'status' => ['sometimes', 'nullable', 'string', Rule::enum(ProcessStatus::class)],
            'competence' => ['sometimes', 'nullable', 'string'],
            'reference_period' => ['sometimes', 'nullable', 'string'],
            'client_id' => ['sometimes', 'nullable', 'integer'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'assignee_membership_id' => ['sometimes', 'nullable', 'integer'],
            'work_process_template_id' => ['sometimes', 'nullable', 'integer'],
            'without_template' => ['sometimes', 'boolean'],
            'include_archived' => ['sometimes', 'boolean'],
            'active_only' => ['sometimes', 'boolean'],
            'include_tasks' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string'],
            'sort' => ['sometimes', 'string', 'in:id,title,competence,status,due_date'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->boolean('without_template')
                    && $this->filled('work_process_template_id')) {
                    $validator->errors()->add(
                        'without_template',
                        'Não combine without_template com work_process_template_id.',
                    );
                }
            },
        ];
    }

    public function filters(): ProcessFiltersData
    {
        $validated = $this->validated();
        $includeTasks = array_key_exists('include_tasks', $validated)
            ? $this->boolean('include_tasks')
            : true;

        return new ProcessFiltersData(
            filters: [
                'status' => $validated['status'] ?? null,
                'competence' => $validated['competence'] ?? null,
                'reference_period' => $validated['reference_period'] ?? null,
                'client_id' => $validated['client_id'] ?? null,
                'department_id' => $validated['department_id'] ?? null,
                'assignee_membership_id' => $validated['assignee_membership_id'] ?? null,
                'work_process_template_id' => $validated['work_process_template_id'] ?? null,
                'without_template' => $this->boolean('without_template'),
                'include_archived' => $this->boolean('include_archived'),
                'active_only' => $this->boolean('active_only'),
                'q' => $validated['q'] ?? null,
                'sort' => $validated['sort'] ?? 'id',
                'direction' => $validated['direction'] ?? 'desc',
                'per_page' => min(
                    max((int) ($validated['per_page'] ?? 25), 1),
                    100,
                ),
                'page' => max((int) ($validated['page'] ?? 1), 1),
            ],
            includeTasks: $includeTasks,
        );
    }
}
