<?php

namespace App\Policies;

use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final readonly class CommunicationInboxPolicy
{
    public function __construct(private Access $access) {}

    public function view(User $user, CommunicationInbox $inbox): bool
    {
        return $this->access->canView($user, $inbox);
    }

    public function reply(User $user, CommunicationInbox $inbox): bool
    {
        return $this->access->canReply($user, $inbox);
    }
}
