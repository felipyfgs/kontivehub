<?php

namespace App\DTO\Communication;

final readonly class CommunicationIdentityLinkData
{
    public function __construct(
        public int $clientId,
        public ?int $clientContactId,
        public bool $isPrimary,
        public bool $receivesAutomatic,
    ) {}
}
