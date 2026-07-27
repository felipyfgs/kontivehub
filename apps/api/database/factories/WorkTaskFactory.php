<?php

namespace Database\Factories;

use App\Enums\Work\TaskStatus;
use App\Models\Tenant;
use App\Models\WorkProcess;
use App\Models\WorkTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkTask>
 */
class WorkTaskFactory extends Factory
{
    protected $model = WorkTask::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'work_process_id' => WorkProcess::factory(),
            'sort_order' => 1,
            'title' => fake()->sentence(3),
            'description' => null,
            'status' => TaskStatus::AFazer,
            'due_date' => now()->addDays(5)->toDateString(),
            'target_due_date' => null,
            'work_department_id' => null,
            'assignee_membership_id' => null,
            'is_required' => true,
            'is_critical' => false,
            'requires_evidence' => false,
            'block_reason' => null,
            'lock_version' => 1,
        ];
    }

    public function critical(): static
    {
        return $this->state(fn () => ['is_critical' => true]);
    }

    public function requiresEvidence(): static
    {
        return $this->state(fn () => ['requires_evidence' => true]);
    }
}
