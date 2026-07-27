<?php

namespace Database\Factories;

use App\Enums\Work\DueRuleType;
use App\Models\Tenant;
use App\Models\WorkProcessTemplate;
use App\Models\WorkProcessTemplateTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkProcessTemplateTask>
 */
class WorkProcessTemplateTaskFactory extends Factory
{
    protected $model = WorkProcessTemplateTask::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'work_process_template_id' => WorkProcessTemplate::factory(),
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
