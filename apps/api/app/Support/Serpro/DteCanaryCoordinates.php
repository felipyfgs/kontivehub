<?php

namespace App\Support\Serpro;

/**
 * Coordenadas imutáveis do canário faturável DTE.
 * Nunca aceitar estes valores do client HTTP.
 */
final class DteCanaryCoordinates
{
    public const OPERATION_KEY = 'dte.consultar';

    public const ID_SISTEMA = 'DTE';

    public const ID_SERVICO = 'CONSULTASITUACAODTE111';

    public const SERVICE_VERSION = '1.0';

    public const FUNCTIONAL_ROUTE = '/Consultar';

    public const REQUIRED_PROXY_POWER = '00050';

    public const CANARY_MAX_QUANTITY = 1;

    public const LIMITED_DEFAULT_MAX_QUANTITY = 10;

    public const ALERT_PERCENT = 80;

    /**
     * @return array{
     *   operation_key: string,
     *   id_sistema: string,
     *   id_servico: string,
     *   service_version: string,
     *   functional_route: string,
     *   required_proxy_power: string
     * }
     */
    public static function asArray(): array
    {
        return [
            'operation_key' => self::OPERATION_KEY,
            'id_sistema' => self::ID_SISTEMA,
            'id_servico' => self::ID_SERVICO,
            'service_version' => self::SERVICE_VERSION,
            'functional_route' => self::FUNCTIONAL_ROUTE,
            'required_proxy_power' => self::REQUIRED_PROXY_POWER,
        ];
    }
}
