<?php

namespace App\DTO\Platform;

use App\Enums\ActivationMethod;
use App\Enums\SubscriptionPlan;

final readonly class PendingTenantCreationData
{
    public function __construct(
        public string $name,
        public TenantInstitutionalProfileData $profile,
        public SubscriptionPlan $plan,
        public string $adminName,
        public string $adminEmail,
        public ActivationMethod $method,
        public string $idempotencyKey,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     profile: array{
     *         cnpj: string|null,
     *         legal_name: string,
     *         institutional_email: string,
     *         institutional_phone: string
     *     },
     *     plan: SubscriptionPlan,
     *     admin_name: string,
     *     admin_email: string,
     *     method: ActivationMethod,
     *     idempotency_key: string
     * }
     */
    public function toServicePayload(): array
    {
        return [
            'name' => $this->name,
            'profile' => $this->profile->toArray(),
            'plan' => $this->plan,
            'admin_name' => $this->adminName,
            'admin_email' => $this->adminEmail,
            'method' => $this->method,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
