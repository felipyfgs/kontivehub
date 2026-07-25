<?php

namespace Database\Factories;

use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\OperationalComment;
use App\Models\OperationalProcess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalComment>
 */
class OperationalCommentFactory extends Factory
{
    protected $model = OperationalComment::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'operational_process_id' => OperationalProcess::factory(),
            'operational_task_id' => null,
            'author_membership_id' => OfficeMembership::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
