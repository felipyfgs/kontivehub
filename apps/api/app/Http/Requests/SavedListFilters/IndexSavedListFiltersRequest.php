<?php

namespace App\Http\Requests\SavedListFilters;

use App\Http\Requests\AuthenticatedRequest;
use App\Models\SavedListFilter;
use Illuminate\Validation\Rule;

final class IndexSavedListFiltersRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'surface' => ['required', 'string', Rule::in(SavedListFilter::SURFACES)],
        ];
    }

    public function surface(): string
    {
        return (string) $this->validated('surface');
    }
}
