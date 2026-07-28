<?php

namespace App\DTO\Tenant;

final readonly class TenantTechnicalConsentGrantData
{
    public function __construct(
        public ?string $versionCode,
        public int $actorUserId,
    ) {}
}
