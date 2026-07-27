<?php

namespace App\Services\Integra\Dctfweb;

/**
 * Códigos estáveis de sistema/serviço/operação DCTFWeb e MIT.
 * Alinhados ao catálogo fiscal e serpro_service_catalog_entries.
 */
final class DctfwebCodes
{
    public const MODULE = 'dctfweb';

    public const SYSTEM_DCTFWEB = 'INTEGRA_DCTFWEB';

    public const SYSTEM_MIT = 'INTEGRA_MIT';

    public const SERVICE_DCTFWEB = 'DCTFWEB';

    public const SERVICE_MIT = 'MIT';

    public const OP_MONITOR = 'MONITOR';

    public const OP_CONSULTAR_RECIBO = 'CONSULTAR_RECIBO';

    public const OP_CONSULTAR_DECLARACAO = 'CONSULTAR_DECLARACAO';

    public const OP_CONSULTAR_RELATORIO = 'CONSULTAR_RELATORIO';

    public const OP_CONSULTAR_XML = 'CONSULTAR_XML';

    public const OP_EMITIR_DARF = 'EMITIR_DARF';

    public const OP_TRANSMITIR = 'TRANSMITIR_DECLARACAO';

    public const OP_MIT_SITUACAO = 'CONSULTAR_SITUACAO';

    public const OP_MIT_APURACAO = 'CONSULTAR_APURACAO';

    public const OP_MIT_LISTAR_APURACOES = 'LISTAR_APURACOES';

    public const OP_MIT_ENCERRAR = 'ENCERRAR';

    public const EVENT_TRANSMISSAO = 'TRANSMISSAO';

    public const EVENT_RETIFICACAO = 'RETIFICACAO';

    public const EVENT_ULTIMA_ATUALIZACAO = 'ULTIMA_ATUALIZACAO';

    public const CATEGORY_DCTFWEB = 'DCTFWEB';

    public const CATEGORY_MIT = 'MIT';

    /** Categoria oficial mensal geral (CONSRECIBO32). */
    public const CATEGORIA_GERAL_MENSAL = '40';

    public const OPERATION_KEY_CONSRECIBO = 'dctfweb.consrecibo';

    public const OPERATION_KEY_CONSDECCOMPLETA = 'dctfweb.consdeccompleta';

    public const OPERATION_KEY_CONSXMLDECLARACAO = 'dctfweb.consxmldeclaracao';

    public const OPERATION_KEY_GERARGUIA = 'dctfweb.gerarguia';

    public const OPERATION_KEY_TRANSDECLARACAO = 'dctfweb.transdeclaracao';

    public const OPERATION_KEY_MIT_SITUACAO = 'mit.situacaoenc';

    public const OPERATION_KEY_MIT_APURACAO = 'mit.consapuracao';

    public const OPERATION_KEY_MIT_LISTA_APURACOES = 'mit.listaapuracoes';

    public const OPERATION_KEY_MIT_ENCERRAR = 'mit.encapuracao';

    public static function operationKey(string $operationCode): string
    {
        return match (strtoupper($operationCode)) {
            self::OP_MONITOR, self::OP_CONSULTAR_RECIBO => self::OPERATION_KEY_CONSRECIBO,
            self::OP_CONSULTAR_DECLARACAO, self::OP_CONSULTAR_RELATORIO => self::OPERATION_KEY_CONSDECCOMPLETA,
            self::OP_CONSULTAR_XML => self::OPERATION_KEY_CONSXMLDECLARACAO,
            self::OP_EMITIR_DARF => self::OPERATION_KEY_GERARGUIA,
            self::OP_TRANSMITIR => self::OPERATION_KEY_TRANSDECLARACAO,
            self::OP_MIT_SITUACAO => self::OPERATION_KEY_MIT_SITUACAO,
            self::OP_MIT_APURACAO => self::OPERATION_KEY_MIT_APURACAO,
            self::OP_MIT_LISTAR_APURACOES => self::OPERATION_KEY_MIT_LISTA_APURACOES,
            self::OP_MIT_ENCERRAR => self::OPERATION_KEY_MIT_ENCERRAR,
            default => throw new \InvalidArgumentException("Operação DCTFWeb/MIT não catalogada: {$operationCode}."),
        };
    }

    /** @return list<string> */
    public static function readOnlyOperationsDctfweb(): array
    {
        return [
            self::OP_MONITOR,
            self::OP_CONSULTAR_RECIBO,
            self::OP_CONSULTAR_DECLARACAO,
            self::OP_CONSULTAR_RELATORIO,
            self::OP_CONSULTAR_XML,
            self::OP_EMITIR_DARF,
        ];
    }

    /** @return list<string> */
    public static function mutatingOperations(): array
    {
        return [
            self::OP_TRANSMITIR,
            self::OP_MIT_ENCERRAR,
        ];
    }
}
