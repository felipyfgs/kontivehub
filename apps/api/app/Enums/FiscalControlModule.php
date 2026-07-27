<?php

namespace App\Enums;

use InvalidArgumentException;

/** Chaves provider-neutral persistidas em fiscal_module_controls. */
enum FiscalControlModule: string
{
    case SimplesMei = 'simples_mei';
    case Dctfweb = 'dctfweb';
    case Installments = 'installments';
    case FiscalSituation = 'sitfis';
    case Mailbox = 'mailbox';
    case Declarations = 'declarations';
    case Guides = 'guides';
    case Fgts = 'fgts';
    case Registrations = 'registrations';
    case FiscalProcesses = 'tax_processes';

    public function label(): string
    {
        return match ($this) {
            self::SimplesMei => 'Simples / MEI',
            self::Dctfweb => 'DCTFWeb / MIT',
            self::Installments => 'Parcelamentos',
            self::FiscalSituation => 'Situação fiscal',
            self::Mailbox => 'Caixa Postal',
            self::Declarations => 'Declarações',
            self::Guides => 'Guias',
            self::Fgts => 'FGTS / eSocial',
            self::Registrations => 'Cadastros',
            self::FiscalProcesses => 'Processos fiscais',
        };
    }

    public static function fromRuntimeKey(string $key): self
    {
        return self::tryFrom(trim($key))
            ?? throw new InvalidArgumentException("Módulo fiscal desconhecido: {$key}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $module): string => $module->value, self::cases());
    }
}
