<?php

namespace App\DTO\Communication;

final readonly class CommunicationCannedResponseDuplicationData
{
    public function __construct(
        public string $shortcut,
        public ?string $title,
    ) {}
}
