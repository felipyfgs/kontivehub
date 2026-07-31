<?php

namespace App\DTO\Tenant;

use App\Models\TenantInstitutionalProfile;

final readonly class InstitutionalProfileUpdateResult
{
    /** @param array<string, mixed> $invalidated */
    public function __construct(
        public TenantInstitutionalProfile $profile,
        public bool $cnpjChanged,
        public array $invalidated,
    ) {}
}
