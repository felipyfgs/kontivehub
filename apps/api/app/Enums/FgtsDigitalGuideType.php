<?php

namespace App\Enums;

enum FgtsDigitalGuideType: string
{
    case Monthly = 'MONTHLY';
    case Termination = 'TERMINATION';
    case Consignment = 'CONSIGNMENT';
    case Mixed = 'MIXED';
    case Parameterized = 'PARAMETERIZED';
}
