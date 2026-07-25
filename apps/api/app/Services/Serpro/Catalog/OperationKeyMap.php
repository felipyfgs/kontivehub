<?php

namespace App\Services\Serpro\Catalog;

/**
 * Mapa estável de códigos legados de domínio → operation_key oficial.
 * Usado enquanto adapters migram para chaves canônicas.
 */
final class OperationKeyMap
{
    /**
     * @var array<string, string> "SYSTEM|SERVICE|OP" => operation_key
     */
    private const LEGACY = [
        // SITFIS
        'SITFIS|SITFIS|SOLICITAR_PROTOCOLO' => 'sitfis.solicitar_protocolo',
        'SITFIS|SITFIS|EMITIR_RELATORIO' => 'sitfis.emitir_relatorio',
        'INTEGRA_SITFIS|SITFIS|SOLICITAR_PROTOCOLO' => 'sitfis.solicitar_protocolo',
        'INTEGRA_SITFIS|SITFIS|EMITIR_RELATORIO' => 'sitfis.emitir_relatorio',

        // DCTFWEB
        'INTEGRA_DCTFWEB|DCTFWEB|MONITOR' => 'dctfweb.consrecibo',
        'INTEGRA_DCTFWEB|DCTFWEB|CONSULTAR_RECIBO' => 'dctfweb.consrecibo',
        'INTEGRA_DCTFWEB|DCTFWEB|CONSULTAR_DECLARACAO' => 'dctfweb.consdeccompleta',
        'INTEGRA_DCTFWEB|DCTFWEB|CONSULTAR_RELATORIO' => 'dctfweb.consdeccompleta',
        'INTEGRA_DCTFWEB|DCTFWEB|CONSULTAR_XML' => 'dctfweb.consxmldeclaracao',
        'INTEGRA_DCTFWEB|DCTFWEB|EMITIR_DARF' => 'dctfweb.gerarguia',
        'INTEGRA_DCTFWEB|DCTFWEB|TRANSMITIR_DECLARACAO' => 'dctfweb.transdeclaracao',
        'DCTFWEB|DCTFWEB|CONSRECIBO' => 'dctfweb.consrecibo',
        'DCTFWEB|DCTFWEB|CONSDECCOMPLETA' => 'dctfweb.consdeccompleta',
        'DCTFWEB|DCTFWEB|CONSXMLDECLARACAO' => 'dctfweb.consxmldeclaracao',
        'DCTFWEB|DCTFWEB|GERARGUIA' => 'dctfweb.gerarguia',
        'DCTFWEB|DCTFWEB|TRANSDECLARACAO' => 'dctfweb.transdeclaracao',

        // MIT
        'INTEGRA_MIT|MIT|CONSULTAR_SITUACAO' => 'mit.situacaoenc',
        'INTEGRA_MIT|MIT|CONSULTAR_APURACAO' => 'mit.consapuracao',
        'INTEGRA_MIT|MIT|LISTAR_APURACOES' => 'mit.listaapuracoes',
        'INTEGRA_MIT|MIT|ENCERRAR' => 'mit.encapuracao',
        'MIT|MIT|SITUACAOENC' => 'mit.situacaoenc',
        'MIT|MIT|CONSAPURACAO' => 'mit.consapuracao',
        'MIT|MIT|LISTAAPURACOES317' => 'mit.listaapuracoes',
        'MIT|MIT|ENCAPURACAO' => 'mit.encapuracao',
        'MIT|MIT|LISTAAPURACOES' => 'mit.listaapuracoes',

        // PGDASD / DEFIS / REGIME (oficiais 13–16)
        'INTEGRA_SN|PGDASD|MONITOR' => 'pgdasd.consdeclaracao',
        'INTEGRA_SN|PGDASD|CONSULTAR_DECLARACAO' => 'pgdasd.consdeclaracao',
        'INTEGRA_SN|PGDASD|CONSULTAR_ULTIMA_DECLARACAO_RECIBO' => 'pgdasd.consultimadecrec',
        'INTEGRA_SN|PGDASD|CONSULTAR_RECIBO' => 'pgdasd.consdecrec',
        'INTEGRA_SN|PGDASD|CONSULTAR_EXTRATO' => 'pgdasd.consextrato',
        'INTEGRA_SN|PGDASD|GERAR_DAS' => 'pgdasd.gerardas',
        'INTEGRA_SN|PGDASD|TRANSMITIR' => 'pgdasd.transdeclaracao',
        'PGDASD|PGDASD|CONSDECLARACAO' => 'pgdasd.consdeclaracao',
        'PGDASD|PGDASD|CONSDECLARACAO13' => 'pgdasd.consdeclaracao',
        'PGDASD|PGDASD|CONSULTIMADECREC' => 'pgdasd.consultimadecrec',
        'PGDASD|PGDASD|CONSULTIMADECREC14' => 'pgdasd.consultimadecrec',
        'PGDASD|PGDASD|CONSDECREC' => 'pgdasd.consdecrec',
        'PGDASD|PGDASD|CONSDECREC15' => 'pgdasd.consdecrec',
        'PGDASD|PGDASD|CONSEXTRATO' => 'pgdasd.consextrato',
        'PGDASD|PGDASD|CONSEXTRATO16' => 'pgdasd.consextrato',
        'PGDASD|PGDASD|TRANSDECLARACAO' => 'pgdasd.transdeclaracao',
        'PGDASD|PGDASD|GERARDAS' => 'pgdasd.gerardas',

        'INTEGRA_SN|DEFIS|MONITOR' => 'defis.consdeclaracao',
        'INTEGRA_SN|DEFIS|CONSULTAR' => 'defis.consdeclaracao',
        'INTEGRA_SN|DEFIS|CONSULTAR_ULTIMA_DECLARACAO_RECIBO' => 'defis.consultimadecrec',
        'INTEGRA_SN|DEFIS|CONSULTAR_DECLARACAO_RECIBO' => 'defis.consdecrec',
        'INTEGRA_SN|DEFIS|TRANSMITIR' => 'defis.transdeclaracao',
        'DEFIS|DEFIS|CONSULTIMADECREC' => 'defis.consultimadecrec',
        'DEFIS|DEFIS|CONSULTIMADECREC143' => 'defis.consultimadecrec',
        'DEFIS|DEFIS|CONSDECREC144' => 'defis.consdecrec',
        'INTEGRA_SN|REGIME_APURACAO|CONSULTAR' => 'regimeapuracao.consultaropcaoregime',
        'INTEGRA_SN|REGIME_APURACAO|CONSULTAR_ANOS_CALENDARIOS' => 'regimeapuracao.consultaranoscalendarios',
        'INTEGRA_SN|REGIME_APURACAO|CONSULTAR_RESOLUCAO' => 'regimeapuracao.consultarresolucao',

        // MEI
        'INTEGRA_MEI|PGMEI|MONITOR' => 'pgmei.dividaativa',
        'INTEGRA_MEI|PGMEI|CONSULTAR' => 'pgmei.dividaativa',
        'INTEGRA_MEI|PGMEI|GERAR_DAS' => 'pgmei.gerardaspdf',
        'INTEGRA_MEI|CCMEI|MONITOR' => 'ccmei.dadosccmei',
        'INTEGRA_MEI|CCMEI|CONSULTAR' => 'ccmei.dadosccmei',
        'INTEGRA_MEI|CCMEI|CONSULTAR_SITUACAO_CADASTRAL' => 'ccmei.ccmeisitcadastral',
        'INTEGRA_MEI|DASN_SIMEI|CONSULTAR' => 'dasnsimei.consultimadecrec',
        'INTEGRA_MEI|DASN_SIMEI|TRANSMITIR' => 'dasnsimei.transdeclaracao',

        // Mailbox
        'CAIXAPOSTAL|CAIXAPOSTAL|LISTAR' => 'caixa_postal.lista',
        'CAIXAPOSTAL|CAIXAPOSTAL|DETALHE' => 'caixa_postal.detalhe',
        'CAIXAPOSTAL|CAIXAPOSTAL|INDICADOR' => 'caixa_postal.indicador',
        'DTE|DTE|CONSULTAR' => 'dte.consultar',

        // Autentica / procurações
        'AUTENTICAPROCURADOR|AUTENTICAPROCURADOR|ENVIO' => 'autentica_procurador.envio_xml_assinado',
        'PROCURACOES|PROCURACOES|OBTER' => 'procuracoes.obter',

        // Cadastro / e-Processo
        'PNRCONTADOR|PNRCONTADOR|VINCULOS' => 'pnr_contador.consultar_vinculos',
        'PNRCONTADOR|PNRCONTADOR|CONSRENUNCIA263' => 'pnr_contador.consultar_renuncias',
        'PNRCONTADOR|PNRCONTADOR|COMPRENUNCIA264' => 'pnr_contador.emitir_comprovante',
        'PNRCONTADOR|PNRCONTADOR|SITSOLICRENUNCIA265' => 'pnr_contador.situacao_renuncia',
        'EPROCESSO|EPROCESSO|CONSULTAR' => 'eprocesso.consultar_por_interessado',

        // SICALC — contrato legado da central de guias para operação oficial 5.1
        'INTEGRA_PAGAMENTO|SICALC|EMITIR_GUIA' => 'sicalc.consolidargerardarf',
        'SICALC|SICALC|EMITIR_GUIA' => 'sicalc.consolidargerardarf',
        'SICALC|SICALC|CONSULTAR_APOIO_RECEITAS' => 'sicalc.consultaapoioreceitas',
        'PAGTOWEB|PAGTOWEB|CONSULTAR_PAGAMENTOS' => 'pagtoweb.pagamentos',
        'PAGTOWEB|PAGTOWEB|CONTAR_CONSULTA_PAGAMENTOS' => 'pagtoweb.contaconsdocarrpg',
    ];

    public static function resolve(
        ?string $operationKey,
        ?string $systemCode = null,
        ?string $serviceCode = null,
        ?string $operationCode = null,
    ): ?string {
        if ($operationKey !== null && trim($operationKey) !== '') {
            return trim($operationKey);
        }

        $sys = strtoupper((string) $systemCode);
        $svc = strtoupper((string) $serviceCode);
        $op = strtoupper((string) $operationCode);
        if ($sys === '' || $op === '') {
            return null;
        }

        $key = "{$sys}|{$svc}|{$op}";
        if (isset(self::LEGACY[$key])) {
            return self::LEGACY[$key];
        }

        // tenta sem service
        $key2 = "{$sys}|{$sys}|{$op}";
        if (isset(self::LEGACY[$key2])) {
            return self::LEGACY[$key2];
        }

        return null;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function require(
        ?string $operationKey,
        ?string $systemCode = null,
        ?string $serviceCode = null,
        ?string $operationCode = null,
    ): string {
        $resolved = self::resolve($operationKey, $systemCode, $serviceCode, $operationCode);
        if ($resolved === null) {
            throw new \InvalidArgumentException(sprintf(
                'operation_key não resolvida (system=%s service=%s op=%s).',
                $systemCode ?? '',
                $serviceCode ?? '',
                $operationCode ?? '',
            ));
        }

        return $resolved;
    }
}
