<?php

namespace Database\Factories;

use App\Enums\OfficeRole;
use App\Enums\TenantRole;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficeMembership>
 */
class OfficeMembershipFactory extends Factory
{
    protected $model = OfficeMembership::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'user_id' => User::factory(),
            'role' => OfficeRole::Operator,
            'tenant_role' => null,
            'permission_profile_id' => null,
            'authorization_version' => 1,
            'is_active' => true,
            'work_department_id' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => OfficeRole::Admin]);
    }

    public function viewer(): static
    {
        return $this->state(fn () => ['role' => OfficeRole::Viewer]);
    }

    /** Membership canônica tenant_admin (perfil nulo). */
    public function tenantAdmin(): static
    {
        return $this->state(fn () => [
            'role' => OfficeRole::Admin,
            'tenant_role' => TenantRole::TenantAdmin,
            'permission_profile_id' => null,
        ]);
    }

    public function tenantUser(TenantPermissionProfile $profile): static
    {
        $legacy = match ($profile->key) {
            TenantPermissionProfile::SYSTEM_LEGACY_OPERATOR => OfficeRole::Operator,
            TenantPermissionProfile::SYSTEM_LEGACY_VIEWER => OfficeRole::Viewer,
            default => OfficeRole::Viewer,
        };

        return $this->state(fn () => [
            'office_id' => $profile->office_id,
            'role' => $legacy,
            'tenant_role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
        ]);
    }

    /** Linha legada explícita (sem colunas canônicas preenchidas). */
    public function legacyOnly(OfficeRole $role = OfficeRole::Operator): static
    {
        return $this->state(fn () => [
            'role' => $role,
            'tenant_role' => null,
            'permission_profile_id' => null,
        ]);
    }
}
