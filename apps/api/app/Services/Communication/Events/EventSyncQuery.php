<?php

namespace App\Services\Communication\Events;

use App\DTO\Communication\EventPageData;
use App\DTO\Communication\EventSyncFiltersData;
use App\Enums\TenantRole;
use App\Models\CommunicationEvent;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use App\Support\CurrentTenant;

final class EventSyncQuery
{
    public function __construct(
        private readonly Access $access,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function execute(
        User $actor,
        EventSyncFiltersData $filters,
    ): EventPageData {
        $visibleInboxIds = $this->access->visibleInboxIds($actor);
        $query = CommunicationEvent::query()
            ->where('id', '>', $filters->after)
            ->where(function ($builder) use ($visibleInboxIds): void {
                $builder->whereIn('inbox_id', $visibleInboxIds);
                if ($this->currentTenant->role() === TenantRole::TenantAdmin
                    || $this->currentTenant->isPlatformPrivileged()) {
                    $builder->orWhereNull('inbox_id');
                }
            })
            ->orderBy('id');

        $rows = $query->limit($filters->limit + 1)->get();
        $hasMore = $rows->count() > $filters->limit;
        $events = $rows->take($filters->limit)->values();

        return new EventPageData(
            events: $events,
            nextCursor: (int) ($events->last()?->id ?? $filters->after),
            hasMore: $hasMore,
        );
    }
}
