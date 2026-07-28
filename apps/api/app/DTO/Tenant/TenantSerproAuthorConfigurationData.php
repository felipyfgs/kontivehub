<?php

namespace App\DTO\Tenant;

use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\SerproEnvironment;

final readonly class TenantSerproAuthorConfigurationData
{
    public function __construct(
        public SerproEnvironment $environment,
        public AuthorIdentityType $identityType,
        public string $identity,
        public ?string $authorName,
        public AuthorCertificateMode $certificateMode,
        public int $actorUserId,
    ) {}
}
