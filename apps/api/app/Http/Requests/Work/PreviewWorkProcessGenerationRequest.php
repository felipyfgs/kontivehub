<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\ProcessGenerationPreviewData;
use App\Models\User;
use App\Models\WorkProcessTemplate;

final class PreviewWorkProcessGenerationRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $template = $this->route('template');

        return $actor instanceof User
            && $template instanceof WorkProcessTemplate
            && $actor->can('generate', $template);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'competence' => ['required', 'string', 'max:16'],
            'selection' => ['present', 'array'],
            'selection.rules' => ['sometimes', 'array'],
            'selection.rules.tax_regimes' => ['sometimes', 'array', 'max:6'],
            'selection.rules.tax_regimes.*' => ['string', 'max:40'],
            'selection.rules.category_ids' => ['sometimes', 'array', 'max:100'],
            'selection.rules.category_ids.*' => ['integer', 'min:1'],
            'selection.rules.category_match' => ['sometimes', 'string', 'in:ANY,ALL'],
            'selection.rules.excluded_category_ids' => ['sometimes', 'array', 'max:100'],
            'selection.rules.excluded_category_ids.*' => ['integer', 'min:1'],
            'selection.include_client_ids' => ['sometimes', 'array', 'max:1000'],
            'selection.include_client_ids.*' => ['integer', 'min:1'],
            'selection.exclude_client_ids' => ['sometimes', 'array', 'max:1000'],
            'selection.exclude_client_ids.*' => ['integer', 'min:1'],
            'overrides' => ['sometimes', 'array'],
            'overrides.due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'overrides.target_due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'overrides.subject_to_fine' => ['sometimes', 'boolean'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function payload(): ProcessGenerationPreviewData
    {
        $validated = $this->validated();

        return new ProcessGenerationPreviewData(
            competence: $validated['competence'],
            selection: $validated['selection'] ?? [],
            overrides: $validated['overrides'] ?? [],
            idempotencyKey: $validated['idempotency_key'] ?? null,
        );
    }
}
