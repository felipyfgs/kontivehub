<?php

namespace Database\Factories;

use App\Models\InstanceBackupRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstanceBackupRun>
 */
class InstanceBackupRunFactory extends Factory
{
    protected $model = InstanceBackupRun::class;

    public function definition(): array
    {
        $started = now()->subMinutes(5);

        return [
            'kind' => InstanceBackupRun::KIND_FULL,
            'status' => InstanceBackupRun::STATUS_SUCCESS,
            'started_at' => $started,
            'finished_at' => $started->addMinutes(2),
            'byte_size' => fake()->numberBetween(1_000, 10_000_000),
            'manifest_path' => 'runs/test/manifest.json',
            'checksum' => hash('sha256', 'test-manifest'),
            'message' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => InstanceBackupRun::STATUS_FAILED,
            'message' => 'Falha sanitizada no backup.',
        ]);
    }

    public function restoreDrill(): static
    {
        return $this->state(fn () => [
            'kind' => InstanceBackupRun::KIND_RESTORE_DRILL,
            'manifest_path' => null,
            'checksum' => null,
        ]);
    }

    public function neverFinished(): static
    {
        return $this->state(fn () => [
            'status' => InstanceBackupRun::STATUS_RUNNING,
            'finished_at' => null,
        ]);
    }
}
