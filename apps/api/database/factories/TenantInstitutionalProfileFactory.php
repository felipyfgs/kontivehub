<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantInstitutionalProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantInstitutionalProfile>
 */
class TenantInstitutionalProfileFactory extends Factory
{
    protected $model = TenantInstitutionalProfile::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'cnpj' => '11222333000181',
            'legal_name' => fake()->company(),
            'institutional_email' => fake()->unique()->companyEmail(),
            'institutional_phone' => '+55 11 3000-0000',
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }

    public function incomplete(): static
    {
        return $this->state(fn () => [
            'cnpj' => null,
            'legal_name' => null,
            'institutional_email' => null,
            'institutional_phone' => null,
        ]);
    }
}
