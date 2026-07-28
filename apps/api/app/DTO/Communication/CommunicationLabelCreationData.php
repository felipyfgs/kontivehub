<?php

namespace App\DTO\Communication;

final readonly class CommunicationLabelCreationData
{
    public function __construct(
        public string $name,
        public string $color,
    ) {}
}
