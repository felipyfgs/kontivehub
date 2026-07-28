<?php

namespace App\DTO\Work;

final readonly class WorkTaskTransitionData
{
    public function __construct(
        public int $lockVersion,
        public ?string $reason = null,
        public ?string $justification = null,
    ) {}
}
