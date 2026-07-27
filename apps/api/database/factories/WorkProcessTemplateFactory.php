<?php

namespace Database\Factories;

use App\Enums\Work\DueRuleType;
use App\Models\Tenant;
use App\Models\WorkProcessTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkProcessTemplate>
 */
class WorkProcessTemplateFactory extends Factory
{
    protected $model = WorkProcessTemplate::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
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
