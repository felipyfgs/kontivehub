<?php

namespace App\DTO\Communication;

final readonly class LabelCreationData
{
    public function __construct(
        public string $name,
        public string $color,
    ) {}
}
