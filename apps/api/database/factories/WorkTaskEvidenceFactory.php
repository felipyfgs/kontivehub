<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\WorkTask;
use App\Models\WorkTaskEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkTaskEvidence>
 */
class WorkTaskEvidenceFactory extends Factory
{
    protected $model = WorkTaskEvidence::class;

    public function definition(): array
    {
        $content = 'fake-evidence-content';

        return [
            'tenant_id' => Tenant::factory(),
            'work_task_id' => WorkTask::factory(),
            'original_filename' => 'evidence.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'vault_object_id' => (string) Str::ulid(),
            'uploaded_by_membership_id' => TenantMembership::factory(),
        ];
    }
}
