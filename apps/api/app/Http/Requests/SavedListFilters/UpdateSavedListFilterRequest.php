<?php

namespace App\Http\Requests\SavedListFilters;

final class UpdateSavedListFilterRequest extends SavedListFilterMutationRequest
{
    protected function partial(): bool
    {
        return true;
    }
}
