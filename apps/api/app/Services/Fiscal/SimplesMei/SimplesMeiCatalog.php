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
            new SimplesMeiOperationDef('INTEGRA_SN', 'PGDASD', 'MONITOR', self::DTO_VERSION, $ro, $full, $sn, ['PGDASD'], 'CONSULTA', true, 'Monitor PGDAS-D (CONSDECLARACAO13)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'PGDASD', 'CONSULTAR_DECLARACAO', self::DTO_VERSION, $ro, $full, $sn, ['PGDASD'], 'CONSULTA', false, 'Consultar declarações por ano/PA (13)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'PGDASD', 'CONSULTAR_ULTIMA_DECLARACAO_RECIBO', self::DTO_VERSION, $ro, $full, $sn, ['PGDASD'], 'CONSULTA', false, 'Consultar última declaração/recibo (14)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'PGDASD', 'CONSULTAR_RECIBO', self::DTO_VERSION, $ro, $full, $sn, ['PGDASD'], 'CONSULTA', false, 'Consultar declaração/recibo por número (15)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'PGDASD', 'CONSULTAR_EXTRATO', self::DTO_VERSION, $ro, $full, $sn, ['PGDASD'], 'CONSULTA', false, 'Consultar extrato do DAS (16)'),
            // Emissão permanece no catálogo de domínio, fora da surface de monitoramento
            new SimplesMeiOperationDef('INTEGRA_SN', 'PGDASD', 'GERAR_DAS', self::DTO_VERSION, $mu, $full, $sn, ['PGDASD'], 'EMISSAO', false, 'Gerar DAS (assistido)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'PGDASD', 'TRANSMITIR', self::DTO_VERSION, $mu, $full, $sn, ['PGDASD'], 'DECLARACAO', false, 'Transmitir PGDAS-D'),

            new SimplesMeiOperationDef('INTEGRA_SN', 'DEFIS', 'MONITOR', self::DTO_VERSION, $ro, $full, $sn, ['DEFIS'], 'CONSULTA', true, 'Monitor DEFIS'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'DEFIS', 'CONSULTAR', self::DTO_VERSION, $ro, $full, $sn, ['DEFIS'], 'CONSULTA', false, 'Consultar DEFIS'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'DEFIS', 'CONSULTAR_ULTIMA_DECLARACAO_RECIBO', self::DTO_VERSION, $ro, $full, $sn, ['DEFIS'], 'CONSULTA', false, 'Consultar última DEFIS e recibo (143)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'DEFIS', 'CONSULTAR_DECLARACAO_RECIBO', self::DTO_VERSION, $ro, $full, $sn, ['DEFIS'], 'CONSULTA', false, 'Consultar declaração DEFIS e recibo (144)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'DEFIS', 'TRANSMITIR', self::DTO_VERSION, $mu, $full, $sn, ['DEFIS'], 'DECLARACAO', false, 'Transmitir DEFIS'),

            new SimplesMeiOperationDef('INTEGRA_SN', 'REGIME_APURACAO', 'CONSULTAR', self::DTO_VERSION, $ro, $full, $sn, ['REGIME_APURACAO', 'PGDASD'], 'CONSULTA', false, 'Consultar Regime de Apuração'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'REGIME_APURACAO', 'CONSULTAR_ANOS_CALENDARIOS', self::DTO_VERSION, $ro, $full, $sn, ['REGIME_APURACAO'], 'CONSULTA', false, 'Consultar anos-calendário do Regime de Apuração (102)'),
            new SimplesMeiOperationDef('INTEGRA_SN', 'REGIME_APURACAO', 'CONSULTAR_RESOLUCAO', self::DTO_VERSION, $ro, $full, $sn, ['REGIME_APURACAO'], 'CONSULTA', false, 'Consultar resolução do Regime de Caixa (104)'),

            // —— MEI (PGMEI, CCMEI, DASN-SIMEI) ——
            new SimplesMeiOperationDef('INTEGRA_MEI', 'PGMEI', 'MONITOR', self::DTO_VERSION, $ro, $full, $mei, ['PGMEI'], 'CONSULTA', true, 'Monitor dívida ativa PGMEI (DIVIDAATIVA24)'),
            new SimplesMeiOperationDef('INTEGRA_MEI', 'PGMEI', 'CONSULTAR', self::DTO_VERSION, $ro, $full, $mei, ['PGMEI'], 'CONSULTA', false, 'Consultar dívida ativa PGMEI (DIVIDAATIVA24)'),
            new SimplesMeiOperationDef('INTEGRA_MEI', 'PGMEI', 'GERAR_DAS', self::DTO_VERSION, $mu, $full, $mei, ['PGMEI'], 'EMISSAO', false, 'Gerar DAS MEI (assistido)'),

            new SimplesMeiOperationDef('INTEGRA_MEI', 'CCMEI', 'MONITOR', self::DTO_VERSION, $ro, $full, $mei, ['CCMEI'], 'CONSULTA', true, 'Monitor CCMEI'),
            new SimplesMeiOperationDef('INTEGRA_MEI', 'CCMEI', 'CONSULTAR', self::DTO_VERSION, $ro, $full, $mei, ['CCMEI'], 'CONSULTA', false, 'Consultar CCMEI'),
            new SimplesMeiOperationDef('INTEGRA_MEI', 'CCMEI', 'CONSULTAR_SITUACAO_CADASTRAL', self::DTO_VERSION, $ro, $full, $mei, ['CCMEI'], 'CONSULTA', false, 'Consultar situação cadastral CCMEI (123)'),

            new SimplesMeiOperationDef('INTEGRA_MEI', 'DASN_SIMEI', 'CONSULTAR', self::DTO_VERSION, $ro, $full, $mei, ['DASN_SIMEI', 'PGMEI'], 'CONSULTA', false, 'Consultar DASN-SIMEI'),
            new SimplesMeiOperationDef('INTEGRA_MEI', 'DASN_SIMEI', 'TRANSMITIR', self::DTO_VERSION, $mu, $full, $mei, ['DASN_SIMEI'], 'DECLARACAO', false, 'Transmitir DASN-SIMEI'),
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
