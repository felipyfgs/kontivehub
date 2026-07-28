<?php

namespace App\DTO\Work;

final readonly class WorkDepartmentData
{
    /** @param array<string, mixed> $attributes */
    public function __construct(public array $attributes) {}
}
