<?php

namespace Database\Factories;

use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\OperationalTask;
use App\Models\OperationalTaskEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OperationalTaskEvidence>
 */
class OperationalTaskEvidenceFactory extends Factory
{
    protected $model = OperationalTaskEvidence::class;

    public function definition(): array
    {
        $content = 'fake-evidence-content';

        return [
            'office_id' => Office::factory(),
            'operational_task_id' => OperationalTask::factory(),
            'original_filename' => 'evidence.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'vault_object_id' => (string) Str::uuid(),
            'uploaded_by_membership_id' => OfficeMembership::factory(),
        ];
    }
}
