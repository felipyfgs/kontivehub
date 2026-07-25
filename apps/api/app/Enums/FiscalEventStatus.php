<?php

namespace App\Enums;

/** Estado de processamento de Evento de Última Atualização. */
enum FiscalEventStatus: string
{
    case Received = 'RECEIVED';
    case Deduplicated = 'DEDUPLICATED';
    case Directed = 'DIRECTED';
    case Processed = 'PROCESSED';
    case Ignored = 'IGNORED';
    case Failed = 'FAILED';
}
