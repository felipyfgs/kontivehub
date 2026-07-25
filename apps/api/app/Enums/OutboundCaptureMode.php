<?php

namespace App\Enums;

/**
 * Modo de recuperação de XML de saída MA.
 * AUTOMATIC só é elegível com contrato M2M (G4); default operacional é ASSISTED.
 */
enum OutboundCaptureMode: string
{
    case Assisted = 'ASSISTED';
    case Automatic = 'AUTOMATIC';

    public function label(): string
    {
        return match ($this) {
            self::Assisted => 'Assistido (pacote oficial)',
            self::Automatic => 'Automático (M2M)',
        };
    }
}
