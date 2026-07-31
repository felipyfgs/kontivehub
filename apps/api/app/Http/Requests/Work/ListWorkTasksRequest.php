<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\QueueFiltersData;
use App\Models\User;
use App\Models\WorkTask;

final class ListWorkTasksRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $actor->can('viewAny', WorkTask::class);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'tab' => ['sometimes', 'string', 'in:open,concluidas,impedidas,todas,hoje,atrasadas,semana,sem_responsavel'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'assignee_membership_id' => ['sometimes', 'nullable', 'integer'],
            'client_id' => ['sometimes', 'nullable', 'integer'],
            'q' => ['sometimes', 'nullable', 'string'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'scope' => ['sometimes', 'string', 'in:mine,department,tenant'],
            'sort' => ['sometimes', 'nullable', 'string', 'in:title,status,effective_due_date,client_name,assignee_name'],
            'direction' => ['sometimes', 'nullable', 'string', 'in:asc,desc'],
        ];
    }

    public function filters(): QueueFiltersData
    {
        $validated = $this->validated();

        return new QueueFiltersData([
            'tab' => $validated['tab'] ?? 'open',
            'department_id' => $validated['department_id'] ?? null,
            'assignee_membership_id' => $validated['assignee_membership_id'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'q' => $validated['q'] ?? null,
            'per_page' => $validated['per_page'] ?? 25,
            'page' => $validated['page'] ?? 1,
            'scope' => $validated['scope'] ?? 'mine',
            'sort' => $validated['sort'] ?? null,
            'direction' => $validated['direction'] ?? null,
        ]);
    }
}
