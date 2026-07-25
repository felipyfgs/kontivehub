<?php

namespace Database\Factories;

use App\Enums\Work\TaskStatus;
use App\Models\Office;
use App\Models\OperationalProcess;
use App\Models\OperationalTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalTask>
 */
class OperationalTaskFactory extends Factory
{
    protected $model = OperationalTask::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'operational_process_id' => OperationalProcess::factory(),
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
