<?php

namespace App\Support\MultitenantRbac;

use App\Enums\OfficeRole;
use App\Enums\PlatformRole;
use App\Enums\TenantRole;
use App\Models\OfficeMembership;
use App\Models\PlatformMembership;
use App\Models\TenantPermissionProfile;
use RuntimeException;

/**
 * Dual-read/dual-write entre storage legado e canônico (sem mudar autoridade).
 *
 * @see openspec/changes/padronizar-autorizacao-multitenant/design.md D3, D11
 */
final class RoleStorageAdapter
{
    /**
     * Preenche colunas canônicas a partir do papel legado (ou mantém canônico).
     * Sombra legada permanece coerente para rollback.
     */
    public function dualWriteOfficeMembership(
        OfficeMembership $membership,
        ?TenantRole $tenantRole = null,
        ?TenantPermissionProfile $profile = null,
    ): void {
        $role = $tenantRole ?? $membership->resolvedTenantRole();
        if ($role === null) {
            return;
        }

        $systemKey = $profile?->key;
        if ($systemKey === null && $membership->role instanceof OfficeRole) {
            $systemKey = match ($membership->role) {
                OfficeRole::Operator => TenantPermissionProfile::SYSTEM_LEGACY_OPERATOR,
                OfficeRole::Viewer => TenantPermissionProfile::SYSTEM_LEGACY_VIEWER,
                default => null,
            };
        }

        $membership->tenant_role = $role;
        $membership->role = $role->legacyOfficeRoleShadow($systemKey);

        if ($role === TenantRole::TenantAdmin) {
            $membership->permission_profile_id = null;
        } elseif ($profile !== null) {
            if (! $profile->belongsToOffice((int) $membership->office_id)) {
                throw new RuntimeException('permission_profile_cross_tenant');
            }
            $membership->permission_profile_id = $profile->id;
        }

        $membership->authorization_version = max(1, (int) $membership->authorization_version);
    }

    public function dualWritePlatformMembership(PlatformMembership $membership, ?PlatformRole $role = null): void
    {
        $resolved = $role ?? $membership->resolvedPlatformRole();
        if ($resolved === null) {
            throw new RuntimeException('platform_role_unresolved');
        }

        $membership->role = $resolved;
        $membership->platform_role = $resolved;
    }

    public function legacyOfficeRoleFromCanonical(
        TenantRole $tenantRole,
        ?string $systemProfileKey = null,
    ): OfficeRole {
        return $tenantRole->legacyOfficeRoleShadow($systemProfileKey);
    }

    public function canonicalPlatformRoleValue(PlatformRole $role): string
    {
        return $role->canonicalValue();
    }
}
