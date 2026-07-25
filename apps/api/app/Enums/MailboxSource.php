<?php

namespace App\Enums;

/**
 * Proveniência oficial — DTE e Caixa Postal não se fundem.
 */
enum MailboxSource: string
{
    case CaixaPostal = 'CAIXA_POSTAL';
    case DteIndicator = 'DTE_INDICATOR';

    public function label(): string
    {
        return match ($this) {
            self::CaixaPostal => 'Caixa Postal',
            self::DteIndicator => 'Indicador DTE',
        };
    }
}
