<?php

namespace App\DTO\Communication;

final readonly class CannedResponseDuplicationData
{
    public function __construct(
        public string $shortcut,
        public ?string $title,
    ) {}
}
