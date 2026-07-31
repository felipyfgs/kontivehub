<?php

namespace App\DTO\Communication;

final readonly class InboxPairingResult
{
    /** @param array<string, mixed> $state */
    public function __construct(
        public array $state,
    ) {}
}
