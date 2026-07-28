<?php

namespace App\DTO\Fiscal\Monitoring;

use App\Models\MailboxClientSyncState;
use App\Models\MailboxContributorState;

final readonly class MailboxStateData
{
    public function __construct(
        public int $tenantId,
        public int $clientId,
        public ?MailboxContributorState $state,
        public ?MailboxClientSyncState $syncState,
    ) {}
}
