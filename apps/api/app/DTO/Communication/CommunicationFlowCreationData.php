<?php

namespace App\DTO\Communication;

final readonly class CommunicationFlowCreationData
{
    public function __construct(
        public string $name,
    ) {}
}
