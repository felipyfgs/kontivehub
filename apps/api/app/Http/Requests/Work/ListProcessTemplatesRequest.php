<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\ProcessTemplateFiltersData;
use App\Models\User;
use App\Models\WorkProcessTemplate;
use Illuminate\Validation\Rule;

final class ListProcessTemplatesRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->can('viewAny', WorkProcessTemplate::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'sort' => ['sometimes', 'string', Rule::in(['name', 'is_active', 'id'])],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): ProcessTemplateFiltersData
    {
        $validated = $this->validated();
        $sort = $validated['sort'] ?? 'name';

        return new ProcessTemplateFiltersData(
            isActive: array_key_exists('is_active', $validated)
                ? $this->boolean('is_active')
                : null,
            search: $validated['q'] ?? null,
            sort: $sort,
            direction: $validated['direction'] ?? ($sort === 'name' ? 'asc' : 'desc'),
            perPage: (int) ($validated['per_page'] ?? 25),
            page: (int) ($validated['page'] ?? 1),
        );
    }
}
