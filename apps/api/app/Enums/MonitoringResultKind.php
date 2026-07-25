<?php

namespace App\Enums;

/**
 * Tipo de retorno confiável da superfície de monitoramento (page-payload-matrix).
 */
enum MonitoringResultKind: string
{
    case Structured = 'STRUCTURED';
    case Pdf = 'PDF';
    case AsyncPdf = 'ASYNC_PDF';
    case Aggregate = 'AGGREGATE';
    case Unavailable = 'UNAVAILABLE';

    public function label(): string
    {
        return match ($this) {
            self::Structured => 'Estruturado',
            self::Pdf => 'PDF',
            self::AsyncPdf => 'PDF assíncrono',
            self::Aggregate => 'Agregado',
            self::Unavailable => 'Indisponível',
        };
    }
}
