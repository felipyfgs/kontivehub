<?php

namespace App\Enums;

/**
 * Estado de encerramento/apuração MIT — correlacionado, porém distinto da DCTFWeb.
 */
enum MitEncerramentoStatus: string
{
    case Unknown = 'UNKNOWN';
    case Open = 'OPEN';
    case Encerrado = 'ENCERRADO';
    case Processing = 'PROCESSING';
    case Error = 'ERROR';
    case Blocked = 'BLOCKED';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Desconhecido',
            self::Open => 'Aberto',
            self::Encerrado => 'Encerrado',
            self::Processing => 'Processando',
            self::Error => 'Erro',
            self::Blocked => 'Bloqueado',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Encerrado;
    }
}
