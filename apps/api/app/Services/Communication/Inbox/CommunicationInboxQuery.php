<?php

namespace App\Services\Communication\Inbox;

use App\DTO\Communication\CommunicationInboxIndexData;
use App\Models\CommunicationInbox;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Support\CurrentTenant;

final readonly class CommunicationInboxQuery
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private CommunicationAccess $access,
    ) {}

    public function index(User $actor): CommunicationInboxIndexData
    {
        $visibleInboxIds = $this->access->visibleInboxIds($actor);

        return new CommunicationInboxIndexData(
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
