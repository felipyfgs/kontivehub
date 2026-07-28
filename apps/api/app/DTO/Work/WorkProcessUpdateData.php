<?php

namespace App\DTO\Work;

final readonly class WorkProcessUpdateData
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public int $lockVersion,
        public array $attributes,
    ) {}
}
