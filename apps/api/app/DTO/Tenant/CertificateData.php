<?php

namespace App\DTO\Tenant;

use App\Models\TenantCredential;
use App\Models\TenantCredentialPurposeLink;
use Illuminate\Support\Collection;

final readonly class CertificateData
{
    /**
     * @param  Collection<int, TenantCredentialPurposeLink>  $purposeLinks
     * @param  list<array<string, mixed>>  $alerts
     * @param  array<string, mixed>|null  $onboarding
     */
    public function __construct(
        public ?TenantCredential $certificate,
        public Collection $purposeLinks,
        public array $alerts,
        public ?array $onboarding = null,
        public bool $removed = false,
    ) {}
}
