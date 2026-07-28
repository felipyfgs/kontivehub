<?php

namespace App\DTO\Tenant;

use App\Enums\SerproEnvironment;

final readonly class TenantSerproTermUploadData
{
    public function __construct(
        public SerproEnvironment $environment,
        public ?string $xml,
        public ?string $filePath,
        public int $actorUserId,
    ) {}
}
