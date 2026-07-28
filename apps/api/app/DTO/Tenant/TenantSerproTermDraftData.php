<?php

namespace App\DTO\Tenant;

use App\Enums\SerproEnvironment;

final readonly class TenantSerproTermDraftData
{
    public function __construct(
        public SerproEnvironment $environment,
        public ?string $validUntil,
        public int $actorUserId,
    ) {}
}
