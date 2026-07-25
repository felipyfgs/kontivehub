<?php

namespace App\Enums;

/** Estado público uniforme de qualquer consulta do workspace fiscal. */
enum MonitoringQueryState: string
{
    case Idle = 'IDLE';
    case Queued = 'QUEUED';
    case Processing = 'PROCESSING';
    case Ready = 'READY';
    case NoData = 'NO_DATA';
    case Failed = 'FAILED';
    case Blocked = 'BLOCKED';
    case Unsupported = 'UNSUPPORTED';

    public function label(): string
    {
        return match ($this) {
            self::Idle => 'Ainda não consultado',
            self::Queued => 'Consulta na fila',
            self::Processing => 'Consulta em processamento',
            self::Ready => 'Resultado disponível',
            self::NoData => 'Consulta concluída sem dados',
            self::Failed => 'Falha na atualização',
            self::Blocked => 'Consulta bloqueada',
            self::Unsupported => 'Sem fonte oficial suportada',
        };
    }
}
