<?php

namespace App\DTO\Communication;

use App\Models\CommunicationInbox;
use App\Models\WorkDepartment;
use Illuminate\Support\Collection;

final readonly class CommunicationInboxIndexData
{
    /**
     * @param  Collection<int, CommunicationInbox>  $inboxes
     * @param  Collection<int, WorkDepartment>  $departments
     */
    public function __construct(
        public Collection $inboxes,
        public Collection $departments,
        public bool $globalEnabled,
        public bool $gatewayEnabled,
        public bool $tenantEnabled,
    ) {}
}
