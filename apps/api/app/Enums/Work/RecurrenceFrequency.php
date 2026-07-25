<?php

namespace App\Enums\Work;

/**
 * Frequência da agenda de recorrência da Rotina.
 */
enum RecurrenceFrequency: string
{
    case Monthly = 'MONTHLY';
    case Quarterly = 'QUARTERLY';
    case Yearly = 'YEARLY';
}
