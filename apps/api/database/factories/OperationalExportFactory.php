<?php

namespace Database\Factories;

use App\Enums\Work\OperationalExportStatus;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\OperationalExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalExport>
 */
class OperationalExportFactory extends Factory
{
    protected $model = OperationalExport::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'requested_by_membership_id' => OfficeMembership::factory(),
            'status' => OperationalExportStatus::Pending,
            'filters_snapshot' => [],
            'storage_path' => null,
            'byte_size' => null,
            'row_count' => 0,
            'error_message' => null,
            'expires_at' => now()->addDay(),
        ];
    }
}
