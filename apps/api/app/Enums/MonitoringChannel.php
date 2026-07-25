<?php

namespace App\Enums;

/**
 * Canal de origem da superfície de monitoramento.
 */
enum MonitoringChannel: string
{
    case Integra = 'INTEGRA';
    case Esocial = 'ESOCIAL';
    case Aggregate = 'AGGREGATE';
    case Internal = 'INTERNAL';

    public function label(): string
    {
        return match ($this) {
            self::Integra => 'Integra Contador',
            self::Esocial => 'eSocial',
            self::Aggregate => 'Agregado',
            self::Internal => 'Interno',
        };
    }
}
