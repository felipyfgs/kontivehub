<?php

namespace App\DTO\Communication;

final readonly class IdentityCreationData
{
    public function __construct(public string $phone) {}
}
