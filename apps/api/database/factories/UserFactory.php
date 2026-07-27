<?php

namespace Database\Factories;

use App\Enums\PlatformRole;
use App\Enums\TenantRole;
use App\Models\PlatformMembership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\SystemTenantPermissionProfiles;
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

    public function forTenant(
        Tenant $tenant,
        TenantRole $role = TenantRole::TenantUser,
        string $permissionProfile = 'operator',
    ): static {
        return $this->afterCreating(function (User $user) use ($tenant, $role, $permissionProfile): void {
            $profiles = app(SystemTenantPermissionProfiles::class)->ensure($tenant);
            $tenant->users()->attach($user->id, [
                'role' => $role->value,
                'permission_profile_id' => $role === TenantRole::TenantUser
                    ? $profiles[$permissionProfile]->id
                    : null,
                'is_active' => true,
            ]);
        });
    }

    /**
     * PLATFORM_ADMIN global — sem membership de tenant e sem acesso fiscal implícito.
     * default_tenant_id opcional via withPlatformDefaultTenant().
     */
    public function asPlatformAdmin(?int $defaultTenantId = null): static
    {
        return $this->afterCreating(function (User $user) use ($defaultTenantId): void {
            $attrs = ['is_active' => true];
            if ($defaultTenantId !== null) {
                $attrs['default_tenant_id'] = $defaultTenantId;
            } else {
                $oldestActive = Tenant::query()
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->value('id');
                if ($oldestActive !== null) {
                    $attrs['default_tenant_id'] = (int) $oldestActive;
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

    public function withPlatformDefaultTenant(int $tenantId): static
    {
        return $this->afterCreating(function (User $user) use ($tenantId): void {
            PlatformMembership::query()
                ->where('user_id', $user->id)
                ->where('role', PlatformRole::PlatformAdmin->value)
                ->update(['default_tenant_id' => $tenantId]);
        });
    }
}
