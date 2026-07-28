<?php

namespace App\DTO\Tenant;

use App\Enums\ActivationMethod;

final readonly class TenantMemberRecipientData
{
    public function __construct(
        public string $name,
        public string $email,
        public ActivationMethod $method,
    ) {}
}
