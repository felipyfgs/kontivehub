<?php

namespace Database\Factories;

use App\Enums\PlatformRole;
use App\Models\PlatformMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformMembership>
 */
class PlatformMembershipFactory extends Factory
{
    protected $model = PlatformMembership::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => PlatformRole::PlatformAdmin,
            'is_active' => true,
            'default_tenant_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function withDefaultTenant(int $tenantId): static
    {
        return $this->state(fn () => ['default_tenant_id' => $tenantId]);
    }
}
