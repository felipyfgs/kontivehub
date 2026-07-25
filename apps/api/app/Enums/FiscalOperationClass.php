<?php

namespace App\Enums;

enum FiscalOperationClass: string
{
    case Read = 'READ';
    case DocumentGeneration = 'DOCUMENT_GENERATION';
    case FiscalMutation = 'FISCAL_MUTATION';
}
