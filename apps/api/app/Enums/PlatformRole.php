<?php

namespace App\Enums;

/** Papéis globais da plataforma, independentes de {@see TenantRole}. */
enum PlatformRole: string
{
    case PlatformAdmin = 'platform_admin';
}
