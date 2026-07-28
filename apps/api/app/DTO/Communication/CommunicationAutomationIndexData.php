<?php

namespace App\DTO\Communication;

use App\Models\CommunicationAutomationPolicy;
use App\Models\CommunicationInbox;
use Illuminate\Support\Collection;

final readonly class CommunicationAutomationIndexData
{
    /**
     * @param  Collection<int, CommunicationAutomationPolicy>  $policies
     * @param  Collection<int, CommunicationInbox>  $inboxes
     * @param  list<string>  $supportedScopes
     */
    public function __construct(
        public Collection $policies,
        public Collection $inboxes,
        public array $supportedScopes,
        public bool $tenantEnabled,
        public bool $globalEnabled,
    ) {}
}
