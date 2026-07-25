<?php

namespace App\Services\FiscalMonitoring\Surfaces;

/**
 * Metadados de integração pertencentes ao workspace. O manifesto oficial
 * continua sendo a fonte de coordenadas e schemas do provider; este registro
 * informa quais handlers consultivos existem no produto e seus parâmetros
 * públicos.
 */
final class MonitoringActionMetadataRegistry
{
    public function handlerFor(string $operationKey): string
    {
        $explicit = [
            'pgdasd.consdeclaracao' => 'pgdasd_documents',
            'pgdasd.consultimadecrec' => 'pgdasd_documents',
            'pgdasd.consdecrec' => 'pgdasd_documents',
            'pgdasd.consextrato' => 'pgdasd_extract',
            'pgmei.dividaativa' => 'pgmei_debt',
            'defis.consdeclaracao' => 'defis_list',
            'defis.consultimadecrec' => 'defis_latest',
            'defis.consdecrec' => 'defis_specific',
            'ccmei.dadosccmei' => 'ccmei_data',
            'ccmei.ccmeisitcadastral' => 'ccmei_status',
            'regimeapuracao.consultaranoscalendarios' => 'regime_calendar',
            'regimeapuracao.consultaropcaoregime' => 'regime_option',
            'regimeapuracao.consultarresolucao' => 'regime_resolution',
            'dctfweb.consrecibo' => 'dctfweb_read',
            'dctfweb.consdeccompleta' => 'dctfweb_read',
            'dctfweb.consxmldeclaracao' => 'dctfweb_read',
            'mit.listaapuracoes' => 'mit_lista',
            'mit.consapuracao' => 'mit_read',
            'mit.situacaoenc' => 'mit_read',
            'sitfis.solicitar_protocolo' => 'sitfis_refresh',
            'sitfis.emitir_relatorio' => 'sitfis_refresh',
            'caixa_postal.lista' => 'mailbox_list',
            'caixa_postal.indicador' => 'mailbox_indicator',
            'caixa_postal.detalhe' => 'mailbox_detail',
            'dte.consultar' => 'dte_status',
            'pagtoweb.pagamentos' => 'pagtoweb_list',
            'pagtoweb.contaconsdocarrpg' => 'pagtoweb_count',
            'sicalc.consultaapoioreceitas' => 'sicalc_support',
            'pnr_contador.consultar_vinculos' => 'registrations_refresh',
            'eprocesso.consultar_por_interessado' => 'tax_process_refresh',
        ];

        if (isset($explicit[$operationKey])) {
            return $explicit[$operationKey];
        }

        return preg_match(
            '/^(parcsn|parcsn_esp|parcmei|parcmei_esp|pertsn|pertmei|relpsn|relpmei)\.(pedidosparc|obterparc|parcelasparagerar|detpagtoparc)$/',
            $operationKey,
        ) === 1 ? 'installment_read' : 'none';
    }

    /**
     * @return list<array{name: string, type: string, required: bool, label: string, pattern?: string|null}>
     */
    public function paramsFor(string $operationKey): array
    {
        $year = [['name' => 'year', 'type' => 'integer', 'required' => true, 'label' => 'Ano-calendário']];
        $period = [['name' => 'period_key', 'type' => 'string', 'required' => true, 'label' => 'Competência (AAAA-MM)', 'pattern' => '^\d{4}-(0[1-9]|1[0-2])$']];
        $pgdasd = [
            ...$period,
            ['name' => 'declaration_number', 'type' => 'string', 'required' => false, 'label' => 'Número da declaração'],
        ];
        $explicit = [
            'pgdasd.consdeclaracao' => [
                ['name' => 'year', 'type' => 'integer', 'required' => false, 'label' => 'Ano-calendário'],
                ['name' => 'period_key', 'type' => 'string', 'required' => false, 'label' => 'Competência (AAAA-MM)', 'pattern' => '^\d{4}-(0[1-9]|1[0-2])$'],
            ],
            'pgdasd.consultimadecrec' => $period,
            'pgdasd.consdecrec' => $pgdasd,
            'pgdasd.consextrato' => [['name' => 'numero_das', 'type' => 'string', 'required' => true, 'label' => 'Número do DAS']],
            'pgmei.dividaativa' => $year,
            'defis.consultimadecrec' => $year,
            'defis.consdecrec' => [['name' => 'reference_id', 'type' => 'integer', 'required' => true, 'label' => 'Referência da declaração']],
            'regimeapuracao.consultaropcaoregime' => $year,
            'regimeapuracao.consultarresolucao' => $year,
            'dctfweb.consrecibo' => $period,
            'dctfweb.consdeccompleta' => $period,
            'dctfweb.consxmldeclaracao' => $period,
            'mit.listaapuracoes' => [
                ['name' => 'anoApuracao', 'type' => 'integer', 'required' => false, 'label' => 'Ano de apuração'],
                ['name' => 'mesApuracao', 'type' => 'integer', 'required' => false, 'label' => 'Mês de apuração'],
            ],
            'mit.consapuracao' => [
                ...$period,
                ['name' => 'id_apuracao', 'type' => 'integer', 'required' => true, 'label' => 'Identificador da apuração'],
            ],
            'mit.situacaoenc' => [
                ...$period,
                ['name' => 'protocolo_encerramento', 'type' => 'string', 'required' => true, 'label' => 'Protocolo de encerramento'],
            ],
            'caixa_postal.detalhe' => [['name' => 'message_id', 'type' => 'string', 'required' => true, 'label' => 'Identificador da mensagem']],
            'pagtoweb.pagamentos' => [['name' => 'filters', 'type' => 'object', 'required' => true, 'label' => 'Filtros de arrecadação']],
            'pagtoweb.contaconsdocarrpg' => [['name' => 'filters', 'type' => 'object', 'required' => true, 'label' => 'Filtros de arrecadação']],
            'sicalc.consultaapoioreceitas' => [['name' => 'codigo_receita', 'type' => 'string', 'required' => true, 'label' => 'Código da receita']],
        ];

        if (isset($explicit[$operationKey])) {
            return $explicit[$operationKey];
        }

        if (preg_match(
            '/^(parcsn|parcsn_esp|parcmei|parcmei_esp|pertsn|pertmei|relpsn|relpmei)\.(pedidosparc|obterparc|parcelasparagerar|detpagtoparc)$/',
            $operationKey,
        ) === 1) {
            return [['name' => 'modality', 'type' => 'string', 'required' => false, 'label' => 'Modalidade']];
        }

        return [];
    }

    /** @return array{system: string, service: string, operation: string}|null */
    public function runCodesFor(string $operationKey): ?array
    {
        return [
            'pgdasd.consdeclaracao' => ['system' => 'INTEGRA_SN', 'service' => 'PGDASD', 'operation' => 'CONSULTAR_DECLARACAO'],
            'pgdasd.consultimadecrec' => ['system' => 'INTEGRA_SN', 'service' => 'PGDASD', 'operation' => 'CONSULTAR_ULTIMA_DECLARACAO_RECIBO'],
            'pgdasd.consdecrec' => ['system' => 'INTEGRA_SN', 'service' => 'PGDASD', 'operation' => 'CONSULTAR_RECIBO'],
            'pgdasd.consextrato' => ['system' => 'INTEGRA_SN', 'service' => 'PGDASD', 'operation' => 'CONSULTAR_EXTRATO'],
            'pgmei.dividaativa' => ['system' => 'INTEGRA_MEI', 'service' => 'PGMEI', 'operation' => 'MONITOR'],
            'defis.consdeclaracao' => ['system' => 'INTEGRA_SN', 'service' => 'DEFIS', 'operation' => 'CONSULTAR'],
            'defis.consultimadecrec' => ['system' => 'INTEGRA_SN', 'service' => 'DEFIS', 'operation' => 'CONSULTAR_ULTIMA_DECLARACAO_RECIBO'],
            'defis.consdecrec' => ['system' => 'INTEGRA_SN', 'service' => 'DEFIS', 'operation' => 'CONSULTAR_DECLARACAO_RECIBO'],
            'ccmei.dadosccmei' => ['system' => 'INTEGRA_MEI', 'service' => 'CCMEI', 'operation' => 'MONITOR'],
            'ccmei.ccmeisitcadastral' => ['system' => 'INTEGRA_MEI', 'service' => 'CCMEI', 'operation' => 'CONSULTAR_SITUACAO_CADASTRAL'],
            'regimeapuracao.consultaranoscalendarios' => ['system' => 'INTEGRA_SN', 'service' => 'REGIME_APURACAO', 'operation' => 'CONSULTAR_ANOS_CALENDARIOS'],
            'regimeapuracao.consultaropcaoregime' => ['system' => 'INTEGRA_SN', 'service' => 'REGIME_APURACAO', 'operation' => 'CONSULTAR'],
            'regimeapuracao.consultarresolucao' => ['system' => 'INTEGRA_SN', 'service' => 'REGIME_APURACAO', 'operation' => 'CONSULTAR_RESOLUCAO'],
            'dctfweb.consrecibo' => ['system' => 'INTEGRA_DCTFWEB', 'service' => 'DCTFWEB', 'operation' => 'CONSULTAR_RECIBO'],
            'dctfweb.consdeccompleta' => ['system' => 'INTEGRA_DCTFWEB', 'service' => 'DCTFWEB', 'operation' => 'CONSULTAR_DECLARACAO'],
            'dctfweb.consxmldeclaracao' => ['system' => 'INTEGRA_DCTFWEB', 'service' => 'DCTFWEB', 'operation' => 'CONSULTAR_XML'],
            'mit.listaapuracoes' => ['system' => 'INTEGRA_MIT', 'service' => 'MIT', 'operation' => 'LISTAR_APURACOES'],
            'mit.consapuracao' => ['system' => 'INTEGRA_MIT', 'service' => 'MIT', 'operation' => 'CONSULTAR_APURACAO'],
            'mit.situacaoenc' => ['system' => 'INTEGRA_MIT', 'service' => 'MIT', 'operation' => 'CONSULTAR_SITUACAO'],
            'caixa_postal.lista' => ['system' => 'INTEGRA_CAIXAPOSTAL', 'service' => 'CAIXA_POSTAL', 'operation' => 'LISTAR'],
            'caixa_postal.indicador' => ['system' => 'INTEGRA_CAIXAPOSTAL', 'service' => 'CAIXA_POSTAL', 'operation' => 'INDICADOR'],
            'caixa_postal.detalhe' => ['system' => 'INTEGRA_CAIXAPOSTAL', 'service' => 'CAIXA_POSTAL', 'operation' => 'DETALHE'],
            'dte.consultar' => ['system' => 'INTEGRA_CAIXAPOSTAL', 'service' => 'DTE', 'operation' => 'CONSULTAR'],
        ][$operationKey] ?? null;
    }

    public function featureModuleFor(string $monitoringModule): ?string
    {
        return match ($monitoringModule) {
            'simples_mei' => 'simples_mei',
            'dctfweb' => 'dctfweb_mit',
            'installments' => 'parcelamentos',
            'sitfis' => 'sitfis',
            'mailbox' => 'mailbox',
            'guides' => 'guias',
            'declarations' => 'declaracoes',
            default => null,
        };
    }
}
