<?php

namespace App\Services\Communication\Canned;

use App\DTO\Communication\CannedResponseFiltersData;
use App\Models\CommunicationCannedResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class CannedResponseQuery
{
    /** @return Collection<int, CommunicationCannedResponse> */
    public function all(CannedResponseFiltersData $filters): Collection
    {
        return $this->query($filters)->get();
    }

    /** @return LengthAwarePaginator<int, CommunicationCannedResponse> */
    public function paginate(
        CannedResponseFiltersData $filters,
    ): LengthAwarePaginator {
        return $this->query($filters)->paginate(
            perPage: $filters->perPage,
            page: $filters->page,
        );
    }

    /** @return Builder<CommunicationCannedResponse> */
    private function query(
        CannedResponseFiltersData $filters,
    ): Builder {
        $query = CommunicationCannedResponse::query();

        if ($filters->manageMode) {
            if ($filters->isActive !== null) {
                $query->where('is_active', $filters->isActive);
            }
        } else {
            $query->where('is_active', true);
        }

        if ($filters->search !== null) {
            $needle = '%'.mb_strtolower($filters->search).'%';
            $query->where(static fn (Builder $builder) => $builder
                ->whereRaw('LOWER(title) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(shortcut) LIKE ?', [$needle]));
        }

        return $query->orderBy('shortcut');
    }
}
