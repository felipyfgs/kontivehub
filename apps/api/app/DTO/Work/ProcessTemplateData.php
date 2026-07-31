<?php

namespace App\DTO\Work;

final readonly class ProcessTemplateData
{
    /** @param array<string, mixed> $attributes */
    public function __construct(public array $attributes) {}
}
