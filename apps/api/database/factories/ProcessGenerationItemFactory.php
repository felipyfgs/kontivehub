<?php

namespace Database\Factories;

use App\Enums\Work\GenerationItemStatus;
use App\Models\Client;
use App\Models\Office;
use App\Models\ProcessGenerationBatch;
use App\Models\ProcessGenerationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessGenerationItem>
 */
class ProcessGenerationItemFactory extends Factory
{
    protected $model = ProcessGenerationItem::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'batch_id' => ProcessGenerationBatch::factory(),
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
