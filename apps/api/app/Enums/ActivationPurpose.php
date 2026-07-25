<?php

namespace App\Enums;

enum ActivationPurpose: string
{
    case OfficeFirstAdmin = 'OFFICE_FIRST_ADMIN';
    case OfficeMember = 'OFFICE_MEMBER';
    case PlatformAdmin = 'PLATFORM_ADMIN';
}
