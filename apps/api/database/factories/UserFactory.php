<?php

namespace Database\Factories;

use App\Enums\OfficeRole;
use App\Enums\PlatformRole;
use App\Models\Office;
use App\Models\PlatformMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
            'password_change_required' => false,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function withTwoFactorConfirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('TESTSECRET'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function forOffice(Office $office, OfficeRole $role = OfficeRole::Viewer): static
    {
        return $this->afterCreating(function (User $user) use ($office, $role): void {
            $office->users()->attach($user->id, [
                'role' => $role->value,
                'is_active' => true,
            ]);
        });
    }

    /**
     * PLATFORM_ADMIN global — sem membership de office e sem acesso fiscal implícito.
     * default_office_id opcional via withPlatformDefaultOffice().
     */
    public function asPlatformAdmin(?int $defaultOfficeId = null): static
    {
        return $this->afterCreating(function (User $user) use ($defaultOfficeId): void {
            $attrs = ['is_active' => true];
            if ($defaultOfficeId !== null) {
                $attrs['default_office_id'] = $defaultOfficeId;
            } else {
                $oldestActive = Office::query()
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->value('id');
                if ($oldestActive !== null) {
                    $attrs['default_office_id'] = (int) $oldestActive;
                }
            }

            PlatformMembership::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'role' => PlatformRole::PlatformAdmin->value,
                ],
                $attrs,
            );
        });
    }

    public function withPlatformDefaultOffice(int $officeId): static
    {
        return $this->afterCreating(function (User $user) use ($officeId): void {
            PlatformMembership::query()
                ->where('user_id', $user->id)
                ->where('role', PlatformRole::PlatformAdmin->value)
                ->update(['default_office_id' => $officeId]);
        });
    }
}
