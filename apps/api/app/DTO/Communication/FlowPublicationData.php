<?php

namespace App\DTO\Communication;

final readonly class FlowPublicationData
{
    public function __construct(
        public int $lockVersion,
    ) {}
}
