<?php

namespace App\DTO\Communication;

final readonly class TenantSettingsData
{
    public function __construct(
        public bool $enabled,
    ) {}
}
