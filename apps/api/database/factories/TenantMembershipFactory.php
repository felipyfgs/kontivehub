<?php

namespace Database\Factories;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantMembership>
 */
class TenantMembershipFactory extends Factory
{
    protected $model = TenantMembership::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'role' => TenantRole::TenantAdmin,
            'permission_profile_id' => null,
            'authorization_version' => 1,
            'is_active' => true,
            'work_department_id' => null,
        ];
    }

    public function admin(): static
    {
        return $this->tenantAdmin();
    }

    /** Membership canônica tenant_admin (perfil nulo). */
    public function tenantAdmin(): static
    {
        return $this->state(fn () => [
            'role' => TenantRole::TenantAdmin,
            'permission_profile_id' => null,
        ]);
    }

    public function tenantUser(TenantPermissionProfile $profile): static
    {
        return $this->state(fn () => [
            'tenant_id' => $profile->tenant_id,
            'role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
        ]);
    }
}
