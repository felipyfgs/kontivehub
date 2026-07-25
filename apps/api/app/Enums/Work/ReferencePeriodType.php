<?php

namespace App\Enums\Work;

/**
 * Granularidade do Período de referência do Processo.
 */
enum ReferencePeriodType: string
{
    case Monthly = 'MONTHLY';
    case Quarterly = 'QUARTERLY';
    case Annual = 'ANNUAL';
}
