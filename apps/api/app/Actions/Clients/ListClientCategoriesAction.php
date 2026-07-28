<?php

namespace App\Actions\Clients;

use App\DTO\Clients\ClientCategoryListFilterData;
use App\Models\ClientCategory;
use Illuminate\Support\Collection;

final readonly class ListClientCategoriesAction
{
    /** @return Collection<int, ClientCategory> */
    public function __invoke(ClientCategoryListFilterData $data): Collection
    {
        return ClientCategory::query()
            ->withCount('clients')
            ->when(
                ! $data->includeArchived,
                fn ($query) => $query->where('is_active', true),
            )
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
}
