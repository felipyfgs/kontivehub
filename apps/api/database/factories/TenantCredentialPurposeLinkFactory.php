<?php

namespace Database\Factories;

use App\Enums\CredentialStatus;
use App\Enums\TenantCredentialPurpose;
use App\Models\Tenant;
use App\Models\TenantCredential;
use App\Models\TenantCredentialPurposeLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantCredentialPurposeLink>
 */
class TenantCredentialPurposeLinkFactory extends Factory
{
    protected $model = TenantCredentialPurposeLink::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'tenant_credential_id' => TenantCredential::factory()->certificate(),
            'purpose' => TenantCredentialPurpose::SerproTermSigning,
            'status' => CredentialStatus::Active,
            'linked_at' => now(),
            'revoked_at' => null,
            'linked_by_user_id' => null,
            'metadata' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }

    public function forCredential(TenantCredential $credential): static
    {
        return $this->state(fn () => [
            'tenant_id' => $credential->tenant_id,
            'tenant_credential_id' => $credential->id,
        ]);
    }

    public function serproTermSigning(): static
    {
        return $this->state(fn () => [
            'purpose' => TenantCredentialPurpose::SerproTermSigning,
        ]);
    }

    public function nfeAutXml(): static
    {
        return $this->state(fn () => [
            'purpose' => TenantCredentialPurpose::NfeAutXmlDistDfe,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => CredentialStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }
}
