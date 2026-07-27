<?php

namespace App\Enums;

use App\Support\CurrentTenant;
use App\Support\PlatformPrivilegedContext;

/**
 * Modo de resolução do tenant em {@see CurrentTenant}.
 */
enum TenantAccessMode: string
{
    /** Membership ativa do usuário no tenant. */
    case Membership = 'membership';

    /**
     * PLATFORM_ADMIN com seleção global (sessão separada, sem membership fictícia).
     *
     * @see PlatformPrivilegedContext
     */
    case PlatformPrivileged = 'platform_privileged';
}
