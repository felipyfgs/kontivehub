<?php

namespace App\DTO\Platform;

final readonly class PlatformOwnerUpdateData
{
    public function __construct(
        public bool $hasName,
        public ?string $name,
        public bool $hasEmail,
        public ?string $email,
        public bool $hasDefaultTenant,
        public ?int $defaultTenantId,
    ) {}

    /** @return array{name?: string, email?: string, default_tenant_id?: int|null} */
    public function toArray(): array
    {
        $data = [];

        if ($this->hasName) {
            $data['name'] = (string) $this->name;
        }
        if ($this->hasEmail) {
            $data['email'] = (string) $this->email;
        }
        if ($this->hasDefaultTenant) {
            $data['default_tenant_id'] = $this->defaultTenantId;
        }

        return $data;
    }
}
