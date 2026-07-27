<?php

namespace Database\Factories;

use App\Enums\Work\WorkExportStatus;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\WorkExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkExport>
 */
class WorkExportFactory extends Factory
{
    protected $model = WorkExport::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'requested_by_membership_id' => TenantMembership::factory(),
            'status' => WorkExportStatus::Pending,
            'filters_snapshot' => [],
            'storage_path' => null,
            'byte_size' => null,
            'row_count' => 0,
            'error_message' => null,
            'expires_at' => now()->addDay(),
        ];
    }
}
