<?php

namespace App\Services\Communication\Events;

use App\DTO\Communication\CommunicationEventPageData;
use App\DTO\Communication\CommunicationEventSyncFiltersData;
use App\Enums\TenantRole;
use App\Models\CommunicationEvent;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Support\CurrentTenant;

final class CommunicationEventSyncQuery
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function execute(
        User $actor,
        CommunicationEventSyncFiltersData $filters,
    ): CommunicationEventPageData {
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

        return new CommunicationEventPageData(
            events: $events,
            nextCursor: (int) ($events->last()?->id ?? $filters->after),
            hasMore: $hasMore,
        );
    }
}
