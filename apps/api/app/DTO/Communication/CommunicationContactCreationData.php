<?php

namespace App\DTO\Communication;

final readonly class CommunicationContactCreationData
{
    public function __construct(
        public ?string $name,
        public string $phone,
        public ?int $clientId,
        public ?int $clientContactId,
        public bool $isPrimary,
        public bool $receivesAutomatic,
    ) {}
}
