<?php

namespace Database\Factories;

use App\Enums\ImportBatchStatus;
use App\Models\DocumentImportBatch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentImportBatch>
 */
class DocumentImportBatchFactory extends Factory
{
    protected $model = DocumentImportBatch::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'created_by' => User::factory(),
            'status' => ImportBatchStatus::Uploaded,
            'file_count' => 0,
            'item_count' => 0,
        ];
    }

    public function forTenant(Tenant $tenant, User $user): static
    {
        return $this->state(fn () => [
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
        ]);
    }
}
