<?php

namespace Database\Factories;

use App\Models\Office;
use App\Models\OfficeInstitutionalProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficeInstitutionalProfile>
 */
class OfficeInstitutionalProfileFactory extends Factory
{
    protected $model = OfficeInstitutionalProfile::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'cnpj' => '11222333000181',
            'legal_name' => fake()->company(),
            'institutional_email' => fake()->unique()->companyEmail(),
            'institutional_phone' => '+55 11 3000-0000',
        ];
    }

    public function forOffice(Office $office): static
    {
        return $this->state(fn () => ['office_id' => $office->id]);
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
