<?php

namespace App\Enums;

use App\Support\CurrentOffice;
use App\Support\PlatformPrivilegedContext;

/**
 * Modo de resolução do tenant em {@see CurrentOffice}.
 */
enum OfficeAccessMode: string
{
    /** Membership ativa do usuário no office. */
    case Membership = 'membership';

    /**
     * PLATFORM_ADMIN com seleção global (sessão separada, sem membership fictícia).
     *
     * @see PlatformPrivilegedContext
     */
    case PlatformPrivileged = 'platform_privileged';
}
