<?php

namespace App\Enums;

enum ActivationPurpose: string
{
    case TenantFirstAdmin = 'TENANT_FIRST_ADMIN';
    case TenantMember = 'TENANT_MEMBER';
    case PlatformAdmin = 'PLATFORM_ADMIN';
}
