<?php

namespace App\Enums;

enum FiscalGuideEmissionStatus: string
{
    case Stub = 'STUB';
    case Issued = 'ISSUED';
    case Expired = 'EXPIRED';
    case Superseded = 'SUPERSEDED';
    case Failed = 'FAILED';
    case Blocked = 'BLOCKED';

    public function label(): string
    {
        return match ($this) {
            self::Stub => 'Stub (piloto)',
            self::Issued => 'Emitida',
            self::Expired => 'Expirada',
            self::Superseded => 'Substituída',
            self::Failed => 'Falha na emissão',
            self::Blocked => 'Bloqueada',
        };
    }
}
