<?php

namespace App\DTO\Work;

final readonly class TaskTransitionData
{
    public function __construct(
        public int $lockVersion,
        public ?string $reason = null,
        public ?string $justification = null,
    ) {}
}
