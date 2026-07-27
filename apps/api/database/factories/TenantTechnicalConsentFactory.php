<?php

namespace Database\Factories;

use App\Enums\TenantCredentialPurpose;
use App\Models\Tenant;
use App\Models\TenantTechnicalConsent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantTechnicalConsent>
 */
class TenantTechnicalConsentFactory extends Factory
{
    protected $model = TenantTechnicalConsent::class;

    public function definition(): array
    {
        $purposes = [
            'CERTIFICATE',
            TenantCredentialPurpose::SerproTermSigning->value,
            TenantCredentialPurpose::NfeAutXmlDistDfe->value,
        ];

        return [
            'tenant_id' => Tenant::factory(),
            'version_code' => TenantTechnicalConsent::VERSION_CERTIFICATE_V1,
            'purposes_presented' => $purposes,
            'actor_user_id' => User::factory(),
            'consented_at' => now(),
            'revoked_at' => null,
            'payload_sha256' => hash('sha256', implode('|', $purposes)),
            'metadata' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }

    public function byUser(User $user): static
    {
        return $this->state(fn () => ['actor_user_id' => $user->id]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'revoked_at' => now(),
        ]);
    }
}
