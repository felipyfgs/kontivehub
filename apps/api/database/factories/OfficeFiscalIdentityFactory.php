<?php

namespace Database\Factories;

use App\Enums\OfficeFiscalIdentityStatus;
use App\Models\Office;
use App\Models\OfficeFiscalIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficeFiscalIdentity>
 */
class OfficeFiscalIdentityFactory extends Factory
{
    protected $model = OfficeFiscalIdentity::class;

    public function definition(): array
    {
        $cnpj = '11222333000181';

        return [
            'office_id' => Office::factory(),
            'cnpj' => $cnpj,
            'root_cnpj' => substr($cnpj, 0, 8),
            'status' => OfficeFiscalIdentityStatus::Active,
            'legal_name' => fake()->company(),
            'activated_at' => now(),
        ];
    }

    public function forOffice(Office $office): static
    {
        return $this->state(fn () => ['office_id' => $office->id]);
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
            'status' => OfficeFiscalIdentityStatus::Inactive,
            'deactivated_at' => now(),
        ]);
    }
}
