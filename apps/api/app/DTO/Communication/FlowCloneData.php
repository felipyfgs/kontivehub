<?php

namespace App\DTO\Communication;

final readonly class FlowCloneData
{
    public function __construct(
        public string $name,
        public ?int $fromVersionId,
    ) {}
}
