<?php

namespace App\Actions\Fiscal;

use App\DTO\Fiscal\Monitoring\MailboxStateData;
use App\Models\MailboxClientSyncState;
use App\Models\Tenant;
use App\Services\Integra\Mailbox\MailboxQueryService;

final readonly class ShowMailboxStateAction
{
    public function __construct(
        private MailboxQueryService $queries,
    ) {}

    public function handle(Tenant $tenant, int $clientId): MailboxStateData
    {
        $state = $this->queries->state($tenant, $clientId);
        $syncState = MailboxClientSyncState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $clientId)
            ->first();

        return new MailboxStateData(
            tenantId: (int) $tenant->id,
            clientId: $clientId,
            state: $state,
            syncState: $syncState,
        );
    }
}
