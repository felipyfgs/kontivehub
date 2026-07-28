<?php

namespace App\DTO\Tenant;

use App\Models\TenantSerproAuthorization;

final readonly class TenantSerproTermDraftResult
{
    public function __construct(
        public TenantSerproAuthorization $authorization,
        public string $draftSha256,
    ) {}
}
