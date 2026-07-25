<?php

namespace Database\Factories;

use App\Enums\Work\DueRuleType;
use App\Models\Office;
use App\Models\ProcessTemplate;
use App\Models\ProcessTemplateTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessTemplateTask>
 */
class ProcessTemplateTaskFactory extends Factory
{
    protected $model = ProcessTemplateTask::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'process_template_id' => ProcessTemplate::factory(),
            'sort_order' => 1,
            'title' => fake()->sentence(3),
            'description' => null,
            'due_rule_type' => DueRuleType::DaysBeforeProcessDue,
            'due_rule_value' => 3,
            'default_department_id' => null,
            'default_assignee_membership_id' => null,
            'is_required' => true,
            'is_critical' => false,
            'requires_evidence' => false,
        ];
    }
}
