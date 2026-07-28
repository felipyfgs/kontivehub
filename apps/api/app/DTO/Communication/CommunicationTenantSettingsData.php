<?php

namespace App\DTO\Communication;

final readonly class CommunicationTenantSettingsData
{
    public function __construct(
        public bool $enabled,
    ) {}
}
