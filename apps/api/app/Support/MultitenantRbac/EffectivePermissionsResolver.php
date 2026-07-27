<?php

namespace App\Support\MultitenantRbac;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\CurrentTenant;

/** Calcula as permissões efetivas anunciadas no contrato HTTP. */
final class EffectivePermissionsResolver
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
    ) {}

    /**
     * @return list<string>
     */
    public function forCurrentContext(User $user): array
    {
        if (! $user->is_active || $this->currentTenant->resolve($user) === null) {
            return [];
        }

        if ($this->currentTenant->isPlatformPrivileged()) {
            return $user->isPlatformAdmin() ? TenantPermission::orderedValues() : [];
        }

        $membership = $this->currentTenant->realMembership();

        return $membership !== null && $membership->is_active
            ? $this->forMembership($membership)
            : [];
    }

    /**
     * @return list<string>
     */
    public function forMembership(TenantMembership $membership): array
    {
        if ($membership->role === TenantRole::TenantAdmin) {
            return TenantPermission::orderedValues();
        }

        if ($membership->role !== TenantRole::TenantUser) {
            return [];
        }

        $profile = $membership->permissionProfile;
        if ($profile === null
            || ! $profile->is_active
            || ! $profile->belongsToTenant((int) $membership->tenant_id)) {
            return [];
        }

        return $profile->permissionKeys();
    }
}
