<?php

namespace App\Enums;

/** Papéis canônicos de uma membership de tenant. */
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
}
