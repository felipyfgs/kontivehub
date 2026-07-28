<?php

namespace App\DTO\Communication;

final readonly class CommunicationInboxCreationData
{
    public function __construct(
        public string $name,
        public bool $isEnabled,
        public bool $isDefault,
        public ?int $workDepartmentId,
    ) {}
}
