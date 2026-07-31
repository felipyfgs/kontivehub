<?php

namespace App\DTO\Work;

final readonly class TaskAssignmentData
{
    /** @param array<string, int|null> $attributes */
    public function __construct(
        public int $lockVersion,
        public array $attributes,
    ) {}
}
