<?php

namespace App\DTO\Communication;

final readonly class FlowCreationData
{
    public function __construct(
        public string $name,
    ) {}
}
