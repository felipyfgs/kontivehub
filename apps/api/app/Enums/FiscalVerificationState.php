<?php

namespace App\Enums;

/**
 * Estado de verificação da evidência/snapshot fiscal.
 */
enum FiscalVerificationState: string
{
    case Unverified = 'UNVERIFIED';
    case Verified = 'VERIFIED';
    case ParseAlert = 'PARSE_ALERT';
    case Rejected = 'REJECTED';
}
