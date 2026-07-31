<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\ProcessUpdateData;
use App\Models\User;
use App\Models\WorkProcess;
use App\Services\Work\MonitoringContextRegistry;
use Illuminate\Validation\Rule;

final class UpdateWorkProcessRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $process = $this->route('process');

        return $actor instanceof User
            && $process instanceof WorkProcess
            && $actor->can('update', $process);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'monitoring_module_key' => [
                'nullable',
                'string',
                Rule::in(app(MonitoringContextRegistry::class)->keys()),
            ],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'target_due_date' => ['nullable', 'date_format:Y-m-d'],
            'subject_to_fine' => ['sometimes', 'boolean'],
            'work_department_id' => ['nullable', 'integer'],
            'assignee_membership_id' => ['nullable', 'integer'],
        ];
    }

    public function updateData(): ProcessUpdateData
    {
        $validated = $this->validated();
        $lockVersion = (int) $validated['lock_version'];
        unset($validated['lock_version']);

        return new ProcessUpdateData($lockVersion, $validated);
    }
}
