<?php

namespace Database\Factories;

use App\Enums\CredentialStatus;
use App\Models\Tenant;
use App\Models\TenantCredential;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantCredential>
 */
class TenantCredentialFactory extends Factory
{
    protected $model = TenantCredential::class;

    public function definition(): array
    {
        $cnpj = '11222333000181';

        return [
            'tenant_id' => Tenant::factory(),
            'status' => CredentialStatus::Active,
            'subject_name' => 'ESCRITORIO TESTE:CNPJ',
            'holder_cnpj' => $cnpj,
            'fingerprint_sha256' => hash('sha256', Str::uuid()->toString()),
            'valid_from' => now()->subYear(),
            'valid_to' => now()->addYear(),
            'vault_object_id' => strtoupper(Str::ulid()->toBase32()),
            'activated_at' => now(),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }

    public function superseded(): static
    {
        return $this->state(fn () => [
            'status' => CredentialStatus::Superseded,
            'superseded_at' => now(),
        ]);
    }

    /** Certificado físico e-CNPJ do tenant. */
    public function certificate(): static
    {
        return $this->state(fn () => []);
    }
}
