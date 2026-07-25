<?php

namespace App\Enums;

/** Origem/gatilho de uma execução de monitoramento fiscal. */
enum FiscalTrigger: string
{
    case Manual = 'MANUAL';
    case Scheduled = 'SCHEDULED';
    case Event = 'EVENT';
    case Reconciliation = 'RECONCILIATION';
    case Continuation = 'CONTINUATION';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Scheduled => 'Agendado',
            self::Event => 'Evento de última atualização',
            self::Reconciliation => 'Reconciliação',
            self::Continuation => 'Continuação (requeue)',
        };
    }
}
