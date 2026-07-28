<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\WorkGenerationBatchFiltersData;
use App\Enums\Work\GenerationBatchStatus;
use App\Models\User;
use App\Models\WorkProcessTemplate;
use Illuminate\Validation\Rule;

final class ListWorkProcessGenerationBatchesRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $template = $this->route('template');

        return $actor instanceof User
            && $template instanceof WorkProcessTemplate
            && $actor->can('viewGenerations', $template);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::enum(GenerationBatchStatus::class)],
            'competence' => ['sometimes', 'nullable', 'string', 'max:16'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): WorkGenerationBatchFiltersData
    {
        $validated = $this->validated();

        return new WorkGenerationBatchFiltersData(
            status: $validated['status'] ?? null,
            competence: $validated['competence'] ?? null,
            perPage: (int) ($validated['per_page'] ?? 25),
            page: (int) ($validated['page'] ?? 1),
        );
    }
}
