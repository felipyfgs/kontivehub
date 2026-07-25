<?php

namespace App\Enums;

enum FiscalModuleAvailabilityState: string
{
    case Available = 'AVAILABLE';
    case GloballyRestricted = 'GLOBALLY_RESTRICTED';
    case OfficeRestricted = 'OFFICE_RESTRICTED';
    case AwaitingConfiguration = 'AWAITING_CONFIGURATION';
    case TechnicalFailure = 'TECHNICAL_FAILURE';
}
