<?php

namespace App\Enums;

/**
 * Papéis tenant canônicos (membership em office_user).
 * Ortogonais a {@see PlatformRole}.
 */
enum TenantRole: string
{
    case TenantAdmin = 'tenant_admin';
    case TenantUser = 'tenant_user';

    public function isAdmin(): bool
    {
        return $this === self::TenantAdmin;
    }

    public function requiresPermissionProfile(): bool
    {
        return $this === self::TenantUser;
    }

    /**
     * Sombra legada segura na coluna office_user.role (dual-write / rollback).
     */
    public function legacyOfficeRoleShadow(?string $systemProfileKey = null): OfficeRole
    {
        if ($this === self::TenantAdmin) {
            return OfficeRole::Admin;
        }

        return match ($systemProfileKey) {
            'legacy-operator' => OfficeRole::Operator,
            'legacy-viewer' => OfficeRole::Viewer,
            default => OfficeRole::Viewer, // perfil custom → sombra conservadora
        };
    }

    public static function tryFromLegacyOfficeRole(OfficeRole $role): self
    {
        return match ($role) {
            OfficeRole::Admin => self::TenantAdmin,
            OfficeRole::Operator, OfficeRole::Viewer => self::TenantUser,
        };
    }
}
