<?php

use App\Models\User;
use App\Services\Communication\Authorization\Access;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('communication.tenant.{tenantId}', function (User $user, int $tenantId): bool {
    return app(Access::class)->canAuthorizeTenantBroadcast($user, $tenantId);
});

Broadcast::channel('communication.inbox.{inboxId}', function (User $user, int $inboxId): bool {
    return app(Access::class)->canAuthorizeInboxBroadcast($user, $inboxId);
});
