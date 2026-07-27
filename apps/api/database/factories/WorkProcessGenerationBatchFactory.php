<?php

namespace Database\Factories;

use App\Enums\Work\GenerationBatchStatus;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\WorkProcessGenerationBatch;
use App\Models\WorkProcessTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkProcessGenerationBatch>
 */
class WorkProcessGenerationBatchFactory extends Factory
{
    protected $model = WorkProcessGenerationBatch::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'work_process_template_id' => WorkProcessTemplate::factory(),
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
            'requested_by_membership_id' => TenantMembership::factory(),
            'expires_at' => now()->addMinutes(30),
        ];
    }
}
