<?php

namespace Database\Factories;

use App\Models\CommunicationCannedResponse;
use App\Models\Tenant;
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
            'tenant_id' => Tenant::factory(),
            'title' => fake()->sentence(3),
            'shortcut' => fake()->unique()->slug(2),
            'body_encrypted' => fake()->paragraph(),
            'is_active' => true,
            'lock_version' => 1,
            'created_by_membership_id' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
