<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\WorkExportFiltersData;
use App\Enums\Work\TaskStatus;
use App\Models\User;
use App\Models\WorkExport;
use Illuminate\Validation\Rule;

final class CreateWorkExportRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->can('create', WorkExport::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'filters' => ['sometimes', 'array'],
            'filters.status' => ['sometimes', 'nullable', 'string', Rule::enum(TaskStatus::class)],
            'filters.department_id' => ['sometimes', 'nullable', 'integer'],
            'filters.client_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function filters(): WorkExportFiltersData
    {
        $validated = $this->validated();

        return new WorkExportFiltersData($validated['filters'] ?? []);
    }
}
