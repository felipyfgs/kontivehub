<?php

namespace App\DTO\Communication;

use App\Enums\Communication\ConversationStatus;

final readonly class CommunicationConversationFiltersData
{
    public function __construct(
        public ?int $inboxId,
        public ?ConversationStatus $status,
        public ?int $assigneeMembershipId,
        public ?int $workDepartmentId,
        public bool $unassigned,
        public bool $unreadOnly,
        public ?string $search,
        public int $perPage,
        public int $page,
    ) {}
}
