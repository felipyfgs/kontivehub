<?php

namespace App\Enums;

/**
 * Modelos fiscais do canal de saída MA (somente 55 e 65 nesta change).
 */
enum OutboundFiscalModel: string
{
    case Nfe = '55';
    case Nfce = '65';

    public function label(): string
    {
        return match ($this) {
            self::Nfe => 'NF-e',
            self::Nfce => 'NFC-e',
        };
    }

    public function documentKind(): DocumentKind
    {
        return match ($this) {
            self::Nfe => DocumentKind::Nfe,
            self::Nfce => DocumentKind::Nfce,
        };
    }

    public function authorizer(): string
    {
        return match ($this) {
            self::Nfe => 'SVAN',
            self::Nfce => 'SVRS',
        };
    }

    public function requiresCscForMutatingProbe(): bool
    {
        return $this === self::Nfce;
    }
}
