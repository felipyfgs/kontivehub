<?php

namespace Database\Factories;

use App\Models\CommunicationCannedResponse;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunicationCannedResponse>
 */
class CommunicationCannedResponseFactory extends Factory
{
    protected $model = CommunicationCannedResponse::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'title' => fake()->sentence(3),
            'shortcut' => fake()->unique()->slug(2),
            'body_encrypted' => fake()->paragraph(),
            'is_active' => true,
            'lock_version' => 1,
            'created_by_membership_id' => null,
        ];
    }

    public function forOffice(Office $office): static
    {
        return $this->state(fn (): array => [
            'office_id' => $office->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
