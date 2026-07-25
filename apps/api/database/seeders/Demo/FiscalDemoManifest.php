<?php

namespace Database\Seeders\Demo;

use App\Enums\FiscalSituation;
use App\Enums\TaxRegimeCode;
use Carbon\CarbonImmutable;

/**
 * Manifesto versionado da fixture fiscal demonstrativa.
 *
 * - 18 clientes sintéticos (16–20)
 * - chaves lógicas estáveis (logical_key)
 * - data-âncora DEMO_FISCAL_ANCHOR_AT
 * - um CNPJ compartilhado com office sentinela (C01)
 */
final class FiscalDemoManifest
{
    public const VERSION = '1.0.0';

    public const DEFAULT_ANCHOR = '2026-06-15T12:00:00-03:00';

    /** Cliente cujo CNPJ se repete no office sentinela. */
    public const SHARED_CNPJ_CLIENT_KEY = 'C01';

    public function __construct(
        private readonly CarbonImmutable $anchor,
        private readonly string $version = self::VERSION,
    ) {}

    public static function fromConfig(): self
    {
        $raw = (string) config('fiscal_demo.anchor_at', self::DEFAULT_ANCHOR);
        $version = (string) config('fiscal_demo.manifest_version', self::VERSION);

        return new self(CarbonImmutable::parse($raw), $version);
    }

    public function version(): string
    {
        return $this->version;
    }

    public function anchor(): CarbonImmutable
    {
        return $this->anchor;
    }

    /**
     * Competência mensal relativa à âncora (0 = mês da âncora, -1 = anterior…).
     */
    public function periodKey(int $monthOffset = 0): string
    {
        return $this->anchor->addMonths($monthOffset)->format('Y-m');
    }

    public function yearKey(int $yearOffset = 0): string
    {
        return (string) ($this->anchor->year + $yearOffset);
    }

    /**
     * @return list<array{
     *   key: string,
     *   legal_name: string,
     *   trade_name: string,
     *   root_cnpj: string,
     *   regime: TaxRegimeCode,
     *   primary_situation: FiscalSituation,
     *   modules: list<string>,
     *   focus: string
     * }>
     */
    public function clients(): array
    {
        // Roots sintéticos 91xxxxxx — fora do catálogo legacy seed-dev.
        return [
            [
                'key' => 'C01',
                'legal_name' => 'Aurora Contábil Demo LTDA',
                'trade_name' => 'Aurora Matriz',
                'root_cnpj' => '91001001',
                'regime' => TaxRegimeCode::SimplesNacional,
                'primary_situation' => FiscalSituation::UpToDate,
                'modules' => ['simples_mei', 'parcelamentos', 'guias', 'sitfis', 'mailbox', 'declaracoes'],
                'focus' => 'UP_TO_DATE SN completo',
            ],
            [
                'key' => 'C02',
                'legal_name' => 'Beta Serviços Digitais ME',
                'trade_name' => 'Beta Digital',
                'root_cnpj' => '91001002',
                'regime' => TaxRegimeCode::SimplesNacional,
                'primary_situation' => FiscalSituation::Pending,
                'modules' => ['simples_mei', 'guias', 'declaracoes'],
                'focus' => 'PENDING PGDAS-D',
            ],
            [
                'key' => 'C03',
                'legal_name' => 'Calebe Tecnologia LTDA',
                'trade_name' => 'Calebe Tech',
                'root_cnpj' => '91001003',
                'regime' => TaxRegimeCode::SimplesNacional,
                'primary_situation' => FiscalSituation::Processing,
                'modules' => ['simples_mei', 'sitfis'],
                'focus' => 'PROCESSING run aberta',
            ],
            [
                'key' => 'C04',
                'legal_name' => 'Delta Comércio Varejista LTDA',
                'trade_name' => 'Delta Loja',
                'root_cnpj' => '91001004',
                'regime' => TaxRegimeCode::SimplesNacional,
                'primary_situation' => FiscalSituation::Attention,
                'modules' => ['simples_mei', 'mailbox', 'parcelamentos'],
                'focus' => 'ATTENTION findings + DTE',
            ],
            [
                'key' => 'C05',
                'legal_name' => 'Echo Logística SA',
                'trade_name' => 'Echo Log',
                'root_cnpj' => '91001005',
                'regime' => TaxRegimeCode::LucroPresumido,
                'primary_situation' => FiscalSituation::Error,
                'modules' => ['dctfweb_mit', 'declaracoes', 'guias'],
                'focus' => 'ERROR DCTFWeb',
            ],
            [
                'key' => 'C06',
                'legal_name' => 'Fenix Indústria de Plásticos LTDA',
                'trade_name' => 'Fenix Plásticos',
                'root_cnpj' => '91001006',
                'regime' => TaxRegimeCode::LucroReal,
                'primary_situation' => FiscalSituation::NotApplicable,
                'modules' => ['simples_mei', 'dctfweb_mit', 'fgts'],
                'focus' => 'NOT_APPLICABLE SN para Lucro Real',
            ],
            [
                'key' => 'C07',
                'legal_name' => 'Gama Consultoria Empresarial LTDA',
                'trade_name' => 'Gama Consult',
                'root_cnpj' => '91001007',
                'regime' => TaxRegimeCode::Unknown,
                'primary_situation' => FiscalSituation::Unknown,
                'modules' => ['sitfis', 'declaracoes'],
                'focus' => 'UNKNOWN sem evidência',
            ],
            [
                'key' => 'C08',
                'legal_name' => 'Helix RH e Folha ME',
                'trade_name' => 'Helix RH',
                'root_cnpj' => '91001008',
                'regime' => TaxRegimeCode::LucroPresumido,
                'primary_situation' => FiscalSituation::Unsupported,
                'modules' => ['fgts', 'dctfweb_mit'],
                'focus' => 'UNSUPPORTED guia/pag FGTS',
            ],
            [
                'key' => 'C09',
                'legal_name' => 'Íris Documentos Fiscais LTDA',
                'trade_name' => 'Íris Docs',
                'root_cnpj' => '91001009',
                'regime' => TaxRegimeCode::SimplesNacional,
                'primary_situation' => FiscalSituation::Blocked,
                'modules' => ['simples_mei', 'mailbox'],
                'focus' => 'BLOCKED 5 decode fails ADN',
            ],
            [
                'key' => 'C10',
                'legal_name' => 'Jade MEI Design',
                'trade_name' => 'Jade Design',
                'root_cnpj' => '91001010',
                'regime' => TaxRegimeCode::Mei,
                'primary_situation' => FiscalSituation::UpToDate,
                'modules' => ['simples_mei', 'guias', 'declaracoes'],
                'focus' => 'UP_TO_DATE MEI PGMEI/DASN',
            ],
            [
                'key' => 'C11',
                'legal_name' => 'Kappa Engenharia Civil LTDA',
                'trade_name' => 'Kappa Eng',
                'root_cnpj' => '91001011',
                'regime' => TaxRegimeCode::LucroPresumido,
                'primary_situation' => FiscalSituation::Pending,
                'modules' => ['dctfweb_mit', 'guias', 'fgts'],
                'focus' => 'PENDING DCTFWeb transmissão',
            ],
            [
                'key' => 'C12',
                'legal_name' => 'Lumen Comunicação Visual ME',
                'trade_name' => 'Lumen Visual',
                'root_cnpj' => '91001012',
                'regime' => TaxRegimeCode::SimplesNacional,
                'primary_situation' => FiscalSituation::Attention,
                'modules' => ['mailbox', 'sitfis', 'parcelamentos'],
                'focus' => 'ATTENTION Caixa Postal crítica',
            ],
            [
                'key' => 'C13',
                'legal_name' => 'Mármore Acabamentos LTDA',
                'trade_name' => 'Mármore',
                'root_cnpj' => '91001013',
                'regime' => TaxRegimeCode::SimplesNacional,
                'primary_situation' => FiscalSituation::Error,
                'modules' => ['parcelamentos', 'guias', 'simples_mei'],
                'focus' => 'ERROR parcelamento atrasado',
            ],
            [
                'key' => 'C14',
                'legal_name' => 'Norte Agropecuária SA',
                'trade_name' => 'Norte Agro',
                'root_cnpj' => '91001014',
                'regime' => TaxRegimeCode::LucroReal,
                'primary_situation' => FiscalSituation::Processing,
                'modules' => ['sitfis', 'dctfweb_mit', 'mailbox'],
                'focus' => 'PROCESSING SITFIS protocolo',
            ],
            [
                'key' => 'C15',
                'legal_name' => 'Órbita Software House LTDA',
                'trade_name' => 'Órbita Soft',
                'root_cnpj' => '91001015',
                'regime' => TaxRegimeCode::SimplesNacional,
                'primary_situation' => FiscalSituation::UpToDate,
                'modules' => ['parcelamentos', 'guias', 'simples_mei', 'declaracoes'],
                'focus' => 'Parcelamentos em dia + guias',
            ],
            [
                'key' => 'C16',
                'legal_name' => 'Prisma Advocacia Associados',
                'trade_name' => 'Prisma Adv',
                'root_cnpj' => '91001016',
                'regime' => TaxRegimeCode::LucroPresumido,
                'primary_situation' => FiscalSituation::Pending,
                'modules' => ['declaracoes', 'guias', 'mailbox'],
                'focus' => 'PENDING declarações em atraso',
            ],
            [
                'key' => 'C17',
                'legal_name' => 'Quasar Transportes LTDA',
                'trade_name' => 'Quasar Transp',
                'root_cnpj' => '91001017',
                'regime' => TaxRegimeCode::SimplesNacional,
                'primary_situation' => FiscalSituation::Attention,
                'modules' => ['simples_mei', 'fgts', 'sitfis', 'guias'],
                'focus' => 'ATTENTION misto FGTS divergência',
            ],
            [
                'key' => 'C18',
                'legal_name' => 'Rigel MEI Artesanato',
                'trade_name' => 'Rigel Arte',
                'root_cnpj' => '91001018',
                'regime' => TaxRegimeCode::Mei,
                'primary_situation' => FiscalSituation::Blocked,
                'modules' => ['simples_mei', 'guias', 'parcelamentos'],
                'focus' => 'BLOCKED MEI mutação/cert',
            ],
        ];
    }

    /**
     * Situações que o dataset garante pelo menos um representante.
     *
     * @return list<FiscalSituation>
     */
    public function requiredSituations(): array
    {
        return FiscalSituation::cases();
    }

    /**
     * @return array{version: string, anchor_at: string, client_count: int, clients: list<string>}
     */
    public function summary(): array
    {
        $clients = $this->clients();

        return [
            'version' => $this->version,
            'anchor_at' => $this->anchor->toIso8601String(),
            'client_count' => count($clients),
            'clients' => array_column($clients, 'key'),
        ];
    }
}
