<?php

namespace App\DTO\Communication;

use App\Enums\Communication\ConversationListSort;
use App\Enums\Communication\ConversationStatus;

final readonly class ConversationFiltersData
{
    /**
     * @param  list<int>  $labelIds
     */
    public function __construct(
        public ?int $inboxId,
        public ?ConversationStatus $status,
        public ?int $assigneeMembershipId,
        public ?int $workDepartmentId,
        public ?int $contactId,
        public bool $unassigned,
        public bool $unreadOnly,
        public ?string $search,
        public array $labelIds,
        public ?ConversationListSort $sortBy,
        public int $perPage,
        public int $page,
        public bool $createSnapshot = false,
        public ?string $snapshotToken = null,
    ) {}
}
