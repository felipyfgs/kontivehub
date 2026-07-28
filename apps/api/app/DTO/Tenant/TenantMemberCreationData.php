<?php

namespace App\DTO\Tenant;

use App\Enums\ActivationMethod;
use App\Enums\TenantRole;

final readonly class TenantMemberCreationData
{
    public function __construct(
        public string $name,
        public string $email,
        public TenantRole $role,
        public ActivationMethod $method,
    ) {}

    /** @return array<string, string|TenantRole|ActivationMethod> */
    public function toServiceInput(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'method' => $this->method,
        ];
    }
}
