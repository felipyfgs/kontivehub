<?php

namespace App\Http\Requests\SavedListFilters;

final class StoreSavedListFilterRequest extends SavedListFilterMutationRequest
{
    protected function partial(): bool
    {
        return false;
    }
}
