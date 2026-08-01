<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\DepartmentFiltersData;
use App\Models\User;
use App\Models\WorkDepartment;

final class ListDepartmentsRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->can('viewAny', WorkDepartment::class);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer'],
        ];
    }

    protected function prepareScopedValidation(): void
    {
        if (! $this->query->has('is_active')) {
            return;
        }

        if (! $this->filled('is_active')) {
            $this->query->remove('is_active');

            return;
        }

        $value = $this->query('is_active');
        if (! is_string($value)) {
            return;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($normalized !== null) {
            $this->query->set('is_active', $normalized);
        }
    }

    public function filters(): DepartmentFiltersData
    {
        return new DepartmentFiltersData(
            isActive: $this->has('is_active')
                ? $this->boolean('is_active')
                : null,
            perPage: min(max($this->integer('per_page', 50), 1), 100),
            page: max($this->integer('page', 1), 1),
        );
    }
}
