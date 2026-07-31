<?php

namespace App\DTO\Work;

final readonly class TaskStructureData
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public array $attributes,
        public ?int $lockVersion = null,
    ) {}
}
