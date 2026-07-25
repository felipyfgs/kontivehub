<?php

namespace App\Enums;

/**
 * Origem da unidade comercial de consulta de monitor SERPRO.
 */
enum MonitorCommercialOrigin: string
{
    case Inaugural = 'inaugural';
    case Manual = 'manual';
    case Scheduled = 'scheduled';

    public function isInaugural(): bool
    {
        return $this === self::Inaugural;
    }
}
