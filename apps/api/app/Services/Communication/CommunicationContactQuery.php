<?php

namespace App\Services\Communication;

use App\DTO\Communication\CommunicationContactFiltersData;
use App\Models\CommunicationContact;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class CommunicationContactQuery
{
    /** @return LengthAwarePaginator<int, CommunicationContact> */
    public function paginate(CommunicationContactFiltersData $filters): LengthAwarePaginator
    {
        $query = CommunicationContact::query()->with([
            'identities.clientLinks.client',
            'identities.clientLinks.clientContact',
        ])->whereNull('merged_into_contact_id');

        if ($filters->search !== null && $filters->search !== '') {
            $needle = '%'.mb_strtolower($filters->search).'%';
            $query->where(fn ($builder) => $builder
                ->whereRaw("LOWER(COALESCE(name, '')) LIKE ?", [$needle])
                ->orWhereHas(
                    'identities',
                    fn ($identities) => $identities
                        ->where('address_masked', 'like', '%'.$filters->search.'%'),
                ));
        }

        if ($filters->isActive !== null) {
            $query->where('is_active', $filters->isActive);
        } elseif (! $filters->includeInactive) {
            $query->where('is_active', true);
        }

        if ($filters->isProvisional !== null) {
            $query->where('is_provisional', $filters->isProvisional);
        }
        if ($filters->linked !== null) {
            $filters->linked
                ? $query->whereHas('identities.clientLinks')
                : $query->whereDoesntHave('identities.clientLinks');
        }

        $this->applySort($query, $filters->sort, $filters->direction);

        return $query->paginate(
            perPage: $filters->perPage,
            page: $filters->page,
        );
    }

    private function applySort(
        Builder $query,
        string $sort,
        string $direction,
    ): void {
        if (! in_array($sort, ['name', 'id', 'created_at'], true)) {
            $query->orderByRaw('name IS NULL')->orderBy('name')->orderBy('id');

            return;
        }

        if ($sort === 'name') {
            $query->orderByRaw('name IS NULL')
                ->orderBy('name', $direction)
                ->orderBy('id', $direction);

            return;
        }

        $query->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('id', $direction);
        }
    }
}
