<?php

namespace Database\Factories;

use App\Enums\Work\DueRuleType;
use App\Models\Office;
use App\Models\ProcessTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessTemplate>
 */
class ProcessTemplateFactory extends Factory
{
    protected $model = ProcessTemplate::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'name' => 'Modelo '.fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'default_department_id' => null,
            'default_due_rule_type' => DueRuleType::FixedDayOfCompetence,
            'default_due_rule_value' => 15,
            'is_active' => true,
            'lock_version' => 1,
            'created_by_membership_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
