<?php

namespace App\Http\Requests\Work;

use App\Domain\Work\ReferencePeriod;
use App\DTO\Work\WorkProcessCreationData;
use App\Models\User;
use App\Models\WorkProcess;
use App\Services\Work\WorkMonitoringContextRegistry;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class StoreWorkProcessRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->can('create', WorkProcess::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'monitoring_module_key' => [
                'nullable',
                'string',
                Rule::in(app(WorkMonitoringContextRegistry::class)->keys()),
            ],
            'competence' => [
                'required',
                'string',
                'max:16',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    try {
                        ReferencePeriod::fromString((string) $value);
                    } catch (InvalidArgumentException) {
                        $fail('O período de referência é inválido.');
                    }
                },
            ],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'target_due_date' => ['nullable', 'date_format:Y-m-d'],
            'subject_to_fine' => ['sometimes', 'boolean'],
            'work_department_id' => ['nullable', 'integer'],
            'assignee_membership_id' => ['nullable', 'integer'],
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*.title' => ['required', 'string', 'max:200'],
            'tasks.*.description' => ['nullable', 'string'],
            'tasks.*.sort_order' => ['sometimes', 'integer', 'min:1'],
            'tasks.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'tasks.*.target_due_date' => ['nullable', 'date_format:Y-m-d'],
            'tasks.*.work_department_id' => ['nullable', 'integer'],
            'tasks.*.assignee_membership_id' => ['nullable', 'integer'],
            'tasks.*.is_required' => ['sometimes', 'boolean'],
            'tasks.*.is_critical' => ['sometimes', 'boolean'],
            'tasks.*.requires_evidence' => ['sometimes', 'boolean'],
        ];
    }

    public function creation(): WorkProcessCreationData
    {
        $validated = $this->validated();
        $tasks = $validated['tasks'];
        unset($validated['tasks']);

        return new WorkProcessCreationData($validated, $tasks);
    }
}
