<?php

namespace App\DTO\Communication;

final readonly class CommunicationFlowPublicationData
{
    public function __construct(
        public int $lockVersion,
    ) {}
}
