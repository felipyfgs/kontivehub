<?php

namespace Database\Factories;

use App\Models\Office;
use App\Models\WorkDepartment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkDepartment>
 */
class WorkDepartmentFactory extends Factory
{
    protected $model = WorkDepartment::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Fiscal', 'Contábil', 'Pessoal', 'Legalização', 'Administrativo', 'RH', 'Comercial',
        ]).' '.fake()->numerify('##');

        return [
            'office_id' => Office::factory(),
            'name' => $name,
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'color' => fake()->hexColor(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
