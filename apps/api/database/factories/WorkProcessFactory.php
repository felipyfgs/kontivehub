<?php

namespace Database\Factories;

use App\Enums\Work\ProcessOrigin;
use App\Enums\Work\ProcessStatus;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\WorkProcess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkProcess>
 */
class WorkProcessFactory extends Factory
{
    protected $model = WorkProcess::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'client_id' => Client::factory(),
            'work_process_template_id' => null,
            'generation_batch_id' => null,
            'origin' => ProcessOrigin::Manual,
            'title' => fake()->sentence(4),
            'description' => null,
            'competence' => now()->format('Y-m'),
            'reference_period_type' => 'MONTHLY',
            'reference_period_start' => now()->startOfMonth()->toDateString(),
            'reference_period_end' => now()->endOfMonth()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'target_due_date' => null,
            'subject_to_fine' => false,
            'work_department_id' => null,
            'assignee_membership_id' => null,
            'status' => ProcessStatus::AFazer,
            'template_snapshot' => null,
            'lock_version' => 1,
            'created_by_membership_id' => null,
            'archived_at' => null,
        ];
    }

    public function fromTemplate(): static
    {
        return $this->state(fn () => [
            'origin' => ProcessOrigin::Template,
        ]);
    }
}
