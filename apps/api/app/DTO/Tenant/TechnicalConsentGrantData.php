<?php

namespace App\DTO\Tenant;

final readonly class TechnicalConsentGrantData
{
    public function __construct(
        public ?string $versionCode,
        public int $actorUserId,
    ) {}
}
