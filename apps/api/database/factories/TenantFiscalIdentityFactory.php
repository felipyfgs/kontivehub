<?php

namespace Database\Factories;

use App\Enums\TenantFiscalIdentityStatus;
use App\Models\Tenant;
use App\Models\TenantFiscalIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantFiscalIdentity>
 */
class TenantFiscalIdentityFactory extends Factory
{
    protected $model = TenantFiscalIdentity::class;

    public function definition(): array
    {
        $cnpj = '11222333000181';

        return [
            'tenant_id' => Tenant::factory(),
            'cnpj' => $cnpj,
            'root_cnpj' => substr($cnpj, 0, 8),
            'status' => TenantFiscalIdentityStatus::Active,
            'legal_name' => fake()->company(),
            'activated_at' => now(),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }

    public function withCnpj(string $cnpj): static
    {
        $normalized = strtoupper(preg_replace('/\W+/', '', $cnpj) ?? $cnpj);

        return $this->state(fn () => [
            'cnpj' => $normalized,
            'root_cnpj' => substr($normalized, 0, 8),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => TenantFiscalIdentityStatus::Inactive,
            'deactivated_at' => now(),
        ]);
    }
}
