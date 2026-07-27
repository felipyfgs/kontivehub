<?php

namespace App\Support\MultitenantRbac;

use App\Enums\PlatformRole;
use App\Enums\TenantRole;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Assistant\AssistantAvailability;
use App\Services\Platform\TenantSwitchService;
use App\Support\CurrentTenant;

/** Payload canônico de identidade para `/api/v1/me`. */
final class MeIdentityPresenter
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TenantSwitchService $tenantSwitch,
        private readonly EffectivePermissionsResolver $permissions,
        private readonly AssistantAvailability $assistantAvailability,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        $tenant = $this->currentTenant->resolve($user);
        $role = $this->currentTenant->role();
        $realRole = $this->currentTenant->realTenantRole();
        $contextStatus = $this->currentTenant->contextStatus()
            ?? ($tenant !== null ? CurrentTenant::CONTEXT_STATUS_OK : CurrentTenant::CONTEXT_STATUS_REQUIRED);

        $organizationName = PlatformSetting::query()
            ->whereKey(PlatformSetting::SINGLETON_ID)
            ->value('organization_name');

        $realMembership = $this->currentTenant->realMembership();
        $tenantRole = $role instanceof TenantRole ? $role : null;
        $realTenantRole = $realRole instanceof TenantRole ? $realRole : null;
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
            ? PlatformRole::PlatformAdmin->value
            : null;

        $tenantPayload = $tenant === null ? null : [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
        ];

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'platform_role' => $platformRole,
            'tenant_role' => $tenantRole?->value,
            'real_tenant_role' => $realTenantRole?->value,
            'effective_permissions' => $effective,
            'permission_profile' => $profileSummary,
            'access_mode' => $this->currentTenant->accessMode()?->value,
            'has_real_membership' => $this->currentTenant->hasRealMembership(),
            'context_status' => $contextStatus,
            'current_tenant' => $tenantPayload,
            'platform_organization_name' => is_string($organizationName) && $organizationName !== ''
                ? $organizationName
                : null,
            'default_tenant_id' => $user->isPlatformAdmin()
                ? $this->currentTenant->defaultTenantId($user)
                : null,
            'memberships' => $this->tenantSwitch->listMemberships($user),

            // Meta fail-closed do assistente de produto (trigger UI).
            'assistant' => [
                'enabled' => $this->assistantAvailability->isEnabled(),
            ],
        ];
    }
}
