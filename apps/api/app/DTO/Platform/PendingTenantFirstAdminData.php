<?php

namespace App\DTO\Platform;

use App\Enums\ActivationMethod;

final readonly class PendingTenantFirstAdminData
{
    public function __construct(
        public string $name,
        public string $email,
        public ActivationMethod $method,
    ) {}
}
