<?php

namespace App\Support\MultitenantRbac;

use App\Enums\OfficeRole;
use App\Enums\PlatformRole;
use App\Enums\TenantRole;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Assistant\AssistantAvailability;
use App\Services\Platform\TenantSwitchService;
use App\Support\CurrentOffice;

/**
 * Payload aditivo de /api/v1/me (canônico + aliases legados).
 */
final class MeIdentityPresenter
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly TenantSwitchService $tenantSwitch,
        private readonly EffectivePermissionsResolver $permissions,
        private readonly AssistantAvailability $assistantAvailability,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        $office = $this->currentOffice->resolve($user);
        $role = $this->currentOffice->role();
        $realRole = $this->currentOffice->realOfficeRole();
        $contextStatus = $this->currentOffice->contextStatus()
            ?? ($office !== null ? CurrentOffice::CONTEXT_STATUS_OK : CurrentOffice::CONTEXT_STATUS_REQUIRED);

        $organizationName = PlatformSetting::query()
            ->whereKey(PlatformSetting::SINGLETON_ID)
            ->value('organization_name');

        $realMembership = $this->currentOffice->realMembership();
        $tenantRole = $this->resolveTenantRoleForPayload($realMembership, $role);
        $realTenantRole = $this->resolveTenantRoleForPayload($realMembership, $realRole);
        $effective = $this->permissions->forCurrentContext($user);

        $profileSummary = null;
        if ($realMembership?->permission_profile_id && $realMembership->relationLoaded('permissionProfile') === false) {
            $realMembership->load('permissionProfile');
        }
        if ($realMembership?->permissionProfile !== null) {
            $profile = $realMembership->permissionProfile;
            $profileSummary = [
                'id' => $profile->id,
                'key' => $profile->key,
                'name' => $profile->name,
                'is_system' => (bool) $profile->is_system,
                'is_active' => (bool) $profile->is_active,
            ];
        }

        $platformRole = $user->isPlatformAdmin()
            ? PlatformRole::PlatformAdmin->canonicalValue()
            : null;

        $officePayload = $office === null ? null : [
            'id' => $office->id,
            'name' => $office->name,
            'slug' => $office->slug,
        ];

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'two_factor_confirmed' => false,
            'two_factor_required' => false,
            'requires_two_factor_setup' => false,

            // Canônico
            'platform_role' => $platformRole,
            'tenant_role' => $tenantRole?->value,
            'real_tenant_role' => $realTenantRole?->value,
            'effective_permissions' => $effective,
            'permission_profile' => $profileSummary,
            'access_mode' => $this->currentOffice->accessMode()?->value,
            'has_real_membership' => $this->currentOffice->hasRealMembership(),
            'context_status' => $contextStatus,
            'current_office' => $officePayload,

            // Aliases legados (somente leitura; não são autoridade)
            'is_platform_admin' => $user->isPlatformAdmin(),
            'role' => $role?->value,
            'real_office_role' => $realRole?->value,
            'office' => $officePayload,

            'platform_organization_name' => is_string($organizationName) && $organizationName !== ''
                ? $organizationName
                : null,
            'default_office_id' => $user->isPlatformAdmin()
                ? $this->currentOffice->defaultOfficeId($user)
                : null,
            'memberships' => $this->tenantSwitch->listMemberships($user),

            // Meta fail-closed do assistente de produto (trigger UI).
            'assistant' => [
                'enabled' => $this->assistantAvailability->isEnabled(),
            ],
        ];
    }

    private function resolveTenantRoleForPayload(
        mixed $membership,
        mixed $officeRole,
    ): ?TenantRole {
        if ($membership !== null && method_exists($membership, 'resolvedTenantRole')) {
            $resolved = $membership->resolvedTenantRole();
            if ($resolved instanceof TenantRole) {
                return $resolved;
            }
        }

        if ($officeRole instanceof OfficeRole) {
            return TenantRole::tryFromLegacyOfficeRole($officeRole);
        }

        return null;
    }
}
