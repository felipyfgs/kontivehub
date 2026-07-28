<?php

namespace App\DTO\Communication;

use App\Enums\Communication\ConversationStatus;

final readonly class CommunicationConversationUpdateData
{
    public function __construct(
        public int $lockVersion,
        public ?ConversationStatus $status,
        public bool $hasStatus,
        public ?int $assigneeMembershipId,
        public bool $hasAssigneeMembershipId,
        public ?int $workDepartmentId,
        public bool $hasWorkDepartmentId,
        public ?int $priority,
        public bool $hasPriority,
        public ?string $snoozedUntil,
        public bool $hasSnoozedUntil,
    ) {}
}
