<?php

namespace App\Enums;

/**
 * Estado de transmissão DCTFWeb — independente do MIT.
 * MUST NOT inferir TRANSMITTED apenas por encerramento MIT.
 */
enum DctfwebTransmissionStatus: string
{
    case Unknown = 'UNKNOWN';
    case Pending = 'PENDING';
    case Transmitted = 'TRANSMITTED';
    case Rectified = 'RECTIFIED';
    case Error = 'ERROR';
    case Blocked = 'BLOCKED';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Desconhecido',
            self::Pending => 'Pendente',
            self::Transmitted => 'Transmitida',
            self::Rectified => 'Retificada',
            self::Error => 'Erro',
            self::Blocked => 'Bloqueada',
        };
    }

    public function isConfirmed(): bool
    {
        return $this === self::Transmitted || $this === self::Rectified;
    }
}
