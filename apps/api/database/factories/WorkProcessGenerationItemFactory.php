<?php

namespace Database\Factories;

use App\Enums\Work\GenerationItemStatus;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\WorkProcessGenerationBatch;
use App\Models\WorkProcessGenerationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkProcessGenerationItem>
 */
class WorkProcessGenerationItemFactory extends Factory
{
    protected $model = WorkProcessGenerationItem::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'batch_id' => WorkProcessGenerationBatch::factory(),
            'client_id' => Client::factory(),
            'status' => GenerationItemStatus::Previewed,
            'is_blocked' => false,
            'preview_payload' => ['title' => 'Preview', 'tasks' => []],
            'alerts' => [],
            'conflicts' => [],
            'created_process_id' => null,
            'error_message' => null,
            'attempt_count' => 0,
        ];
    }
}
