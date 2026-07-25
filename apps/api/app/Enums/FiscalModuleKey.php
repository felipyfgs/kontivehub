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
        return match ($this) {
            self::Dashboard => null,
            self::SimplesMei => 'simples_mei',
            self::Dctfweb => 'dctfweb_mit',
            self::Installments => 'parcelamentos',
            self::Sitfis => 'sitfis',
            self::Mailbox => 'mailbox',
            self::Declarations => 'declaracoes',
            self::Guides => 'guias',
            self::Fgts => 'fgts',
            self::Registrations => 'registrations',
            self::TaxProcesses => 'tax_processes',
        };
    }

    /** Path canônico da SPA de monitoramento. */
    public function monitoringPath(): string
    {
        return match ($this) {
            self::Dashboard => '/monitoring',
            self::SimplesMei => '/monitoring/simples-mei',
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
        $normalized = strtolower(trim($module));
        $normalized = str_replace('-', '_', $normalized);

        // Aliases de feature flag / categorias
        $aliases = [
            'dctfweb_mit' => self::Dctfweb,
            'parcelamentos' => self::Installments,
            'declaracoes' => self::Declarations,
            'guias' => self::Guides,
            'simples' => self::SimplesMei,
            'cadastro' => self::Registrations,
            'vinculos' => self::Registrations,
            'eprocesso' => self::TaxProcesses,
            'processos' => self::TaxProcesses,
        ];

        if (isset($aliases[$normalized])) {
            return $aliases[$normalized];
        }

        return self::tryFrom($normalized);
    }

    public static function tryFromPath(string $path): ?self
    {
        $path = '/'.trim($path, '/');
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
            // Abas do hub Declarações + agregado legado DECLARACOES.
            self::Declarations => [
                'PGDAS',
                'DEFIS',
                'DASN_SIMEI',
                'DCTFWEB',
                'MIT',
                'FGTS',
                'DIRF',
                'DECLARACOES',
            ],
            self::Guides => ['GUIAS'],
            self::Fgts => ['FGTS'],
            self::Registrations => ['PNRCONTADOR'],
            self::TaxProcesses => ['EPROCESSO'],
            self::Dashboard => [],
        };
    }
}
