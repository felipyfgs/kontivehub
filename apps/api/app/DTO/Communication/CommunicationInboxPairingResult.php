<?php

namespace App\DTO\Communication;

final readonly class CommunicationInboxPairingResult
{
    /** @param array<string, mixed> $state */
    public function __construct(
        public array $state,
    ) {}
}
