<?php

namespace App\Enums;

enum FiscalVerificationKind: string
{
    case SerproApi = 'SERPRO_API';
    case PortalArtifact = 'PORTAL_ARTIFACT';
    case Unverified = 'UNVERIFIED';
}
