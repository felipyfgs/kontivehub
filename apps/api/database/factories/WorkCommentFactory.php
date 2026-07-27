<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\WorkComment;
use App\Models\WorkProcess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkComment>
 */
class WorkCommentFactory extends Factory
{
    protected $model = WorkComment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'work_process_id' => WorkProcess::factory(),
            'work_task_id' => null,
            'author_membership_id' => TenantMembership::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
