<?php

namespace App\Enums;

/**
 * Identificadores tipados de módulo do Monitoramento (UI + read model).
 * featureFlagKey() mapeia para FeatureFlags::MODULES quando aplicável.
 */
enum FiscalModuleKey: string
{
    case Dashboard = 'dashboard';
    case SimplesMei = 'simples_mei';
    case Dctfweb = 'dctfweb';
    case Installments = 'installments';
    case Sitfis = 'sitfis';
    case Mailbox = 'mailbox';
    case Declarations = 'declarations';
    case Guides = 'guides';
    case Fgts = 'fgts';
    case Registrations = 'registrations';
    case TaxProcesses = 'tax_processes';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::SimplesMei => 'Simples / MEI',
            self::Dctfweb => 'DCTFWeb / MIT',
            self::Installments => 'Parcelamentos',
            self::Sitfis => 'Situação Fiscal',
            self::Mailbox => 'Caixa Postal',
            self::Declarations => 'Declarações',
            self::Guides => 'Guias',
            self::Fgts => 'FGTS / eSocial',
            self::Registrations => 'Cadastro e vínculos',
            self::TaxProcesses => 'Processos fiscais',
        };
    }

    /**
     * Chave em FeatureFlags / fiscal_categories.module_key.
     * Dashboard não possui flag própria.
     */
    public function featureFlagKey(): ?string
    {
        return $this === self::Dashboard ? null : $this->value;
    }

    /** Path canônico da SPA de monitoramento. */
    public function monitoringPath(): string
    {
        return match ($this) {
            self::Dashboard => '/monitoring',
            self::SimplesMei => '/monitoring/simples',
            self::Dctfweb => '/monitoring/dctfweb',
            self::Installments => '/monitoring/installments',
            self::Sitfis => '/monitoring/sitfis',
            self::Mailbox => '/monitoring/mailbox',
            self::Declarations => '/monitoring/declarations',
            self::Guides => '/monitoring/guides',
            self::Fgts => '/monitoring/fgts',
            self::Registrations => '/monitoring/registrations',
            self::TaxProcesses => '/monitoring/tax-processes',
        };
    }

    /**
     * Módulos com carteira/overview REST (exclui dashboard agregado).
     *
     * @return list<self>
     */
    public static function portfolioModules(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $m) => $m !== self::Dashboard,
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $m) => $m->value, self::cases());
    }

    public static function tryFromRoute(string $module): ?self
    {
        return self::tryFrom(trim($module));
    }

    public static function tryFromPath(string $path): ?self
    {
        $path = '/'.trim($path, '/');
        if ($path === '/monitoring/mei') {
            return self::SimplesMei;
        }
        foreach (self::cases() as $case) {
            if ($case->monitoringPath() === $path) {
                return $case;
            }
            // /monitoring/mailbox/123 → mailbox
            if ($case !== self::Dashboard && str_starts_with($path, $case->monitoringPath().'/')) {
                return $case;
            }
        }

        return null;
    }

    /** Submódulos opcionais aceitos no filtro `submodule` (SQL). */
    public function knownSubmodules(): array
    {
        return match ($this) {
            self::SimplesMei => ['PGDASD', 'PGMEI'],
            self::Dctfweb => ['DCTFWEB', 'MIT'],
            self::Installments => ['PARCELAMENTOS'],
            self::Sitfis => ['SITFIS'],
            self::Mailbox => ['CAIXA_POSTAL'],
            self::Declarations => [
                'PGDAS',
                'DEFIS',
                'DASN_SIMEI',
                'DCTFWEB',
                'MIT',
                'FGTS',
                'DIRF',
            ],
            self::Guides => ['GUIAS'],
            self::Fgts => ['FGTS'],
            self::Registrations => ['PNRCONTADOR'],
            self::TaxProcesses => ['EPROCESSO'],
            self::Dashboard => [],
        };
    }
}
