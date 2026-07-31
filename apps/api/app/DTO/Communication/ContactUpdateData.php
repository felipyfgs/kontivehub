<?php

namespace App\DTO\Communication;

final readonly class ContactUpdateData
{
    /** @param array<string, mixed> $attributes */
    public function __construct(public array $attributes) {}
}
