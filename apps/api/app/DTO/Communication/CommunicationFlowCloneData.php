<?php

namespace App\DTO\Communication;

final readonly class CommunicationFlowCloneData
{
    public function __construct(
        public string $name,
        public ?int $fromVersionId,
    ) {}
}
