<?php

namespace App\DTO\Tenant;

final readonly class TenantInstitutionalProfileUpdateData
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public array $attributes,
        public int $actorUserId,
    ) {}
}
