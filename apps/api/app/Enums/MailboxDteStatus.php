<?php

namespace App\Enums;

enum MailboxDteStatus: string
{
    case Unknown = 'UNKNOWN';
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Error = 'ERROR';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Desconhecido',
            self::Active => 'DTE ativo',
            self::Inactive => 'DTE inativo',
            self::Error => 'Erro na consulta DTE',
        };
    }
}
