<?php

namespace App\DTO\Communication;

final readonly class CommunicationInboxUpdateData
{
    public function __construct(
        public ?string $name,
        public ?bool $isEnabled,
        public ?bool $isDefault,
        public ?int $workDepartmentId,
        public bool $hasWorkDepartmentId,
        public int $lockVersion,
    ) {}
}
