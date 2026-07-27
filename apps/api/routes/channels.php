<?php

use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('communication.tenant.{tenantId}', function (User $user, int $tenantId): bool {
    return app(CommunicationAccess::class)->canAuthorizeTenantBroadcast($user, $tenantId);
});

Broadcast::channel('communication.inbox.{inboxId}', function (User $user, int $inboxId): bool {
    return app(CommunicationAccess::class)->canAuthorizeInboxBroadcast($user, $inboxId);
});
