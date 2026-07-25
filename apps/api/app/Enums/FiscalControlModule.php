<?php

namespace App\Enums;

use InvalidArgumentException;

/** Chaves provider-neutral persistidas em fiscal_module_controls. */
enum FiscalControlModule: string
{
    case SimplesMei = 'simples_mei';
    case Dctfweb = 'dctfweb';
    case Installments = 'parcelamentos';
    case FiscalSituation = 'situacao_fiscal';
    case Mailbox = 'caixa_postal';
    case Declarations = 'declaracoes';
    case Guides = 'guias';
    case Fgts = 'fgts';
    case Registrations = 'cadastros';
    case FiscalProcesses = 'processos_fiscais';

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
        $normalized = strtolower(str_replace('-', '_', trim($key)));
        $aliases = [
            'dctfweb_mit' => self::Dctfweb,
            'installments' => self::Installments,
            'sitfis' => self::FiscalSituation,
            'mailbox' => self::Mailbox,
            'declarations' => self::Declarations,
            'guides' => self::Guides,
            'registrations' => self::Registrations,
            'tax_processes' => self::FiscalProcesses,
        ];

        return $aliases[$normalized] ?? self::tryFrom($normalized)
            ?? throw new InvalidArgumentException("Módulo fiscal desconhecido: {$key}");
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $module): string => $module->value, self::cases());
    }
}
