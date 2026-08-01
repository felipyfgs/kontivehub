<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\TaskReorderData;
use App\Models\User;
use App\Models\WorkProcess;

final class ReorderTasksRequest extends TenantScopedRequest
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
            'order' => ['required', 'array', 'min:1'],
            'order.*.id' => ['required', 'integer'],
            'order.*.sort_order' => ['required', 'integer', 'min:1'],
            'order.*.lock_version' => ['required', 'integer', 'min:1'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function reorder(): TaskReorderData
    {
        $validated = $this->validated();

        return new TaskReorderData(
            order: $validated['order'],
            justification: $validated['justification'] ?? null,
        );
    }
}
