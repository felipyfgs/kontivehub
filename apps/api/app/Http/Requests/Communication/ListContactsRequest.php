<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\ContactFiltersData;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class ListContactsRequest extends TenantScopedRequest
{
    protected function prepareScopedValidation(): void
    {
        foreach ([
            'is_active',
            'include_inactive',
            'is_provisional',
            'linked',
        ] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => $this->boolean($field)]);
            }
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(Access::class)->canView($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'q' => $this->isMethod('POST')
                ? ['required', 'string', 'min:1', 'max:200']
                : ['sometimes', 'nullable', 'string', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
            'include_inactive' => ['sometimes', 'boolean'],
            'is_provisional' => ['sometimes', 'boolean'],
            'linked' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', 'max:40'],
            'sort_direction' => ['sometimes', 'string', 'max:8'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): ContactFiltersData
    {
        $validated = $this->validated();

        return new ContactFiltersData(
            search: isset($validated['q']) ? trim((string) $validated['q']) : null,
            phoneSearch: $this->isMethod('POST'),
            isActive: array_key_exists('is_active', $validated)
                ? $this->boolean('is_active')
                : null,
            includeInactive: $this->boolean('include_inactive'),
            isProvisional: array_key_exists('is_provisional', $validated)
                ? $this->boolean('is_provisional')
                : null,
            linked: array_key_exists('linked', $validated)
                ? $this->boolean('linked')
                : null,
            sort: (string) ($validated['sort'] ?? ''),
            direction: strtolower((string) ($validated['sort_direction'] ?? 'asc')) === 'desc'
                ? 'desc'
                : 'asc',
            perPage: (int) ($validated['per_page'] ?? 30),
            page: (int) ($validated['page'] ?? 1),
        );
    }
}
