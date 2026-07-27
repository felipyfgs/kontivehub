<?php

namespace App\Services\Fiscal\SimplesMei;

use App\DTO\Fiscal\SimplesMei\SimplesMeiOperationDef;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\TaxRegimeCode;

/**
 * Catálogo versionado de operações Integra-SN / Integra-MEI.
 * Fonte única para adapters, elegibilidade e UI.
 */
final class SimplesMeiCatalog
{
    public const MODULE = 'simples_mei';

    public const DTO_VERSION = '1';

    /**
     * @return list<SimplesMeiOperationDef>
     */
    public static function all(): array
    {
        $ro = FiscalMutability::ReadOnly;
        $mu = FiscalMutability::Mutating;
        $full = FiscalCoverage::Full;
        $sn = TaxRegimeCode::SimplesNacional;
        $mei = TaxRegimeCode::Mei;

        return [
            // —— Simples Nacional (PGDAS-D oficiais 13–16 + emissão fora da superfície de monitoramento) ——
            new SimplesMeiOperationDef('INTEGRA_SN', 'PGDASD', 'MONITOR', 'pgdasd.consdeclaracao', self::DTO_VERSION, $ro, $full, $sn, ['00146'], 'CONSULTA', true, 'Monitor PGDAS-D (CONSDECLARACAO13)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'PGDASD', 'CONSULTAR_DECLARACAO', 'pgdasd.consdeclaracao', self::DTO_VERSION, $ro, $full, $sn, ['00146'], 'CONSULTA', false, 'Consultar declarações por ano/PA (13)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'PGDASD', 'CONSULTAR_ULTIMA_DECLARACAO_RECIBO', 'pgdasd.consultimadecrec', self::DTO_VERSION, $ro, $full, $sn, ['00146'], 'CONSULTA', false, 'Consultar última declaração/recibo (14)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'PGDASD', 'CONSULTAR_RECIBO', 'pgdasd.consdecrec', self::DTO_VERSION, $ro, $full, $sn, ['00146'], 'CONSULTA', false, 'Consultar declaração/recibo por número (15)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'PGDASD', 'CONSULTAR_EXTRATO', 'pgdasd.consextrato', self::DTO_VERSION, $ro, $full, $sn, ['00146'], 'CONSULTA', false, 'Consultar extrato do DAS (16)'),
            // Emissão permanece no catálogo de domínio, fora da surface de monitoramento
            new SimplesMeiOperationDef('INTEGRA_SN', 'PGDASD', 'GERAR_DAS', 'pgdasd.gerardas', self::DTO_VERSION, $mu, $full, $sn, ['00146'], 'EMISSAO', false, 'Gerar DAS (assistido)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'PGDASD', 'TRANSMITIR', 'pgdasd.transdeclaracao', self::DTO_VERSION, $mu, $full, $sn, ['00146'], 'DECLARACAO', false, 'Transmitir PGDAS-D'),

            new SimplesMeiOperationDef('INTEGRA_SN', 'DEFIS', 'MONITOR', 'defis.consdeclaracao', self::DTO_VERSION, $ro, $full, $sn, ['00146'], 'CONSULTA', true, 'Monitor DEFIS'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'DEFIS', 'CONSULTAR', 'defis.consdeclaracao', self::DTO_VERSION, $ro, $full, $sn, ['00146'], 'CONSULTA', false, 'Consultar DEFIS'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'DEFIS', 'CONSULTAR_ULTIMA_DECLARACAO_RECIBO', 'defis.consultimadecrec', self::DTO_VERSION, $ro, $full, $sn, ['00146'], 'CONSULTA', false, 'Consultar última DEFIS e recibo (143)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'DEFIS', 'CONSULTAR_DECLARACAO_RECIBO', 'defis.consdecrec', self::DTO_VERSION, $ro, $full, $sn, ['00146'], 'CONSULTA', false, 'Consultar declaração DEFIS e recibo (144)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'DEFIS', 'TRANSMITIR', 'defis.transdeclaracao', self::DTO_VERSION, $mu, $full, $sn, ['00146'], 'DECLARACAO', false, 'Transmitir DEFIS'),

            new SimplesMeiOperationDef('INTEGRA_SN', 'REGIME_APURACAO', 'CONSULTAR', 'regimeapuracao.consultaropcaoregime', self::DTO_VERSION, $ro, $full, $sn, ['00060'], 'CONSULTA', false, 'Consultar Regime de Apuração'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'REGIME_APURACAO', 'CONSULTAR_ANOS_CALENDARIOS', 'regimeapuracao.consultaranoscalendarios', self::DTO_VERSION, $ro, $full, $sn, ['00060'], 'CONSULTA', false, 'Consultar anos-calendário do Regime de Apuração (102)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'REGIME_APURACAO', 'CONSULTAR_RESOLUCAO', 'regimeapuracao.consultarresolucao', self::DTO_VERSION, $ro, $full, $sn, ['00060'], 'CONSULTA', false, 'Consultar resolução do Regime de Caixa (104)'),

            // —— MEI (PGMEI, CCMEI, DASN-SIMEI) ——
            new SimplesMeiOperationDef('INTEGRA_MEI', 'PGMEI', 'MONITOR', 'pgmei.dividaativa', self::DTO_VERSION, $ro, $full, $mei, [], 'CONSULTA', true, 'Monitor dívida ativa PGMEI (DIVIDAATIVA24)'),
            new SimplesMeiOperationDef('INTEGRA_MEI', 'PGMEI', 'CONSULTAR', 'pgmei.dividaativa', self::DTO_VERSION, $ro, $full, $mei, [], 'CONSULTA', false, 'Consultar dívida ativa PGMEI (DIVIDAATIVA24)'),
            new SimplesMeiOperationDef('INTEGRA_MEI', 'PGMEI', 'GERAR_DAS', 'pgmei.gerardaspdf', self::DTO_VERSION, $mu, $full, $mei, [], 'EMISSAO', false, 'Gerar DAS MEI (assistido)'),

            new SimplesMeiOperationDef('INTEGRA_MEI', 'CCMEI', 'MONITOR', 'ccmei.dadosccmei', self::DTO_VERSION, $ro, $full, $mei, [], 'CONSULTA', true, 'Monitor CCMEI'),
            new SimplesMeiOperationDef('INTEGRA_MEI', 'CCMEI', 'CONSULTAR', 'ccmei.dadosccmei', self::DTO_VERSION, $ro, $full, $mei, [], 'CONSULTA', false, 'Consultar CCMEI'),
            new SimplesMeiOperationDef('INTEGRA_MEI', 'CCMEI', 'CONSULTAR_SITUACAO_CADASTRAL', 'ccmei.ccmeisitcadastral', self::DTO_VERSION, $ro, $full, $mei, [], 'CONSULTA', false, 'Consultar situação cadastral CCMEI (123)'),

            new SimplesMeiOperationDef('INTEGRA_MEI', 'DASN_SIMEI', 'CONSULTAR', 'dasnsimei.consultimadecrec', self::DTO_VERSION, $ro, $full, $mei, ['00229'], 'CONSULTA', false, 'Consultar DASN-SIMEI'),
            new SimplesMeiOperationDef('INTEGRA_MEI', 'DASN_SIMEI', 'TRANSMITIR', 'dasnsimei.transdeclaracao', self::DTO_VERSION, $mu, $full, $mei, [], 'DECLARACAO', false, 'Transmitir DASN-SIMEI'),
        ];
    }

    public static function find(string $system, string $service, string $operation): ?SimplesMeiOperationDef
    {
        foreach (self::all() as $def) {
            if (
                strcasecmp($def->systemCode, $system) === 0
                && strcasecmp($def->serviceCode, $service) === 0
                && strcasecmp($def->operationCode, $operation) === 0
            ) {
                return $def;
            }
        }

        return null;
    }

    /**
     * @return list<SimplesMeiOperationDef>
     */
    public static function byRegime(TaxRegimeCode $regime): array
    {
        return array_values(array_filter(
            self::all(),
            fn (SimplesMeiOperationDef $d) => $d->regimeFamily === $regime,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function toPublicCatalog(): array
    {
        return array_map(static function (SimplesMeiOperationDef $d): array {
            return [
                'system_code' => $d->systemCode,
                'service_code' => $d->serviceCode,
                'operation_code' => $d->operationCode,
                'operation_key' => $d->operationKey,
                'label' => $d->label,
                'dto_version' => $d->dtoVersion,
                'mutability' => $d->mutability->value,
                'coverage' => $d->coverage->value,
                'regime_family' => $d->regimeFamily->value,
                'required_powers' => $d->requiredPowers,
                'billable_class' => $d->billableClass,
                'is_monitor' => $d->isMonitor,
                'module' => self::MODULE,
            ];
        }, self::all());
    }
}
