<?php

namespace Database\Factories;

use App\Enums\ImportBatchStatus;
use App\Models\DocumentImportBatch;
use App\Models\Office;
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
            'office_id' => Office::factory(),
            'created_by' => User::factory(),
            'status' => ImportBatchStatus::Uploaded,
            'file_count' => 0,
            'item_count' => 0,
        ];
    }

    public function forOffice(Office $office, User $user): static
    {
        return $this->state(fn () => [
            'office_id' => $office->id,
            'created_by' => $user->id,
        ]);
    }
}
