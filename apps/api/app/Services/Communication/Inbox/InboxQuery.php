<?php

namespace App\Services\Communication\Inbox;

use App\DTO\Communication\InboxIndexData;
use App\Models\CommunicationInbox;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Services\Communication\Authorization\Access;
use App\Support\CurrentTenant;

final readonly class InboxQuery
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private Access $access,
    ) {}

    public function index(User $actor): InboxIndexData
    {
        $visibleInboxIds = $this->access->visibleInboxIds($actor);

        return new InboxIndexData(
            inboxes: CommunicationInbox::query()
                ->whereIn('id', $visibleInboxIds)
                ->with(['members' => fn ($query) => $query
                    ->where('is_active', true)
                    ->with('membership.user')])
                ->withCount(['members' => fn ($query) => $query->where('is_active', true)])
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
            departments: WorkDepartment::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'color', 'is_active']),
            globalEnabled: (bool) config('communication.enabled'),
            gatewayEnabled: (bool) config('communication.gateway.enabled'),
            tenantEnabled: (bool) $this->currentTenant->tenant()->communication_enabled,
        );
    }
}
