<?php

use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('communication.office.{officeId}', function (User $user, int $officeId): bool {
    return app(CommunicationAccess::class)->canAuthorizeOfficeBroadcast($user, $officeId);
});

Broadcast::channel('communication.inbox.{inboxId}', function (User $user, int $inboxId): bool {
    return app(CommunicationAccess::class)->canAuthorizeInboxBroadcast($user, $inboxId);
});
