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
            'platform_role' => null,
            'is_active' => true,
            'default_office_id' => null,
        ];
    }

    /** Dual-write canônico preenchido (sem cutover de autoridade). */
    public function withCanonicalRole(): static
    {
        return $this->state(fn () => [
            'role' => PlatformRole::PlatformAdmin,
            'platform_role' => PlatformRole::PlatformAdmin->canonicalValue(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function withDefaultOffice(int $officeId): static
    {
        return $this->state(fn () => ['default_office_id' => $officeId]);
    }
}
