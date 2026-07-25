<?php

namespace App\Services\Integra\Dctfweb;

/**
 * Códigos estáveis de sistema/serviço/operação DCTFWeb e MIT.
 * Alinhados ao catálogo fiscal e serpro_service_catalog_entries.
 */
final class DctfwebCodes
{
    public const MODULE = 'dctfweb_mit';

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

    public const OPERATION_KEY_MIT_LISTA_APURACOES = 'mit.listaapuracoes';

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
