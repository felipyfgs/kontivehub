<?php

namespace Database\Factories;

use App\Enums\Work\GenerationBatchStatus;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\ProcessGenerationBatch;
use App\Models\ProcessTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProcessGenerationBatch>
 */
class ProcessGenerationBatchFactory extends Factory
{
    protected $model = ProcessGenerationBatch::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'process_template_id' => ProcessTemplate::factory(),
            'template_lock_version' => 1,
            'competence' => now()->format('Y-m'),
            'reference_period_type' => 'MONTHLY',
            'reference_period_start' => now()->startOfMonth()->toDateString(),
            'reference_period_end' => now()->endOfMonth()->toDateString(),
            'status' => GenerationBatchStatus::Previewed,
            'payload_hash' => hash('sha256', Str::uuid()->toString()),
            'idempotency_key' => (string) Str::uuid(),
            'request_snapshot' => [],
            'preview_summary' => [],
            'requested_by_membership_id' => OfficeMembership::factory(),
            'expires_at' => now()->addMinutes(30),
        ];
    }
}
