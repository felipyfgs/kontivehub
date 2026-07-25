<?php

/**
 * Monitoramento parcial FGTS via eSocial (tasks 12.x).
 *
 * Cobertura: eventos S-5003, S-5013 e fechamento S-1299 quando disponíveis.
 * Guia, pagamento e pendências do portal FGTS Digital = UNSUPPORTED (sem API pública).
 * Scraping / Gov.br / CAPTCHA / cookie NÃO são fallback.
 */
return [
    /** Provider oficial eSocial BX. Default deliberadamente fechado. */
    'driver' => env('FGTS_ESOCIAL_DRIVER', 'disabled'),
    'environment' => env('FGTS_ESOCIAL_ENVIRONMENT', 'restricted'),
    'production_egress_enabled' => filter_var(
        env('FGTS_ESOCIAL_PRODUCTION_EGRESS_ENABLED', false),
        FILTER_VALIDATE_BOOL,
    ),

    'official_bx' => [
        'schema_version' => 'v1_0_0',
        'wsdl_version' => 'v1_0_0',
        'daily_access_limit' => (int) env('FGTS_ESOCIAL_DAILY_ACCESS_LIMIT', 10),
        'batch_limit' => 50,
        'blocked_days' => range(1, 7),
        'timezone' => env('FGTS_ESOCIAL_TIMEZONE', 'America/Sao_Paulo'),
        'minimum_lag_minutes' => (int) env('FGTS_ESOCIAL_MINIMUM_LAG_MINUTES', 60),
        'max_query_interval_days' => (int) env('FGTS_ESOCIAL_MAX_QUERY_INTERVAL_DAYS', 31),
        'lock_seconds' => (int) env('FGTS_ESOCIAL_LOCK_SECONDS', 180),
        'connect_timeout_seconds' => (int) env('FGTS_ESOCIAL_CONNECT_TIMEOUT_SECONDS', 15),
        'timeout_seconds' => (int) env('FGTS_ESOCIAL_TIMEOUT_SECONDS', 90),
        'user_agent' => env('FGTS_ESOCIAL_USER_AGENT', 'FiscalHub-eSocialBX/1.0'),
        'endpoints' => [
            'restricted' => [
                'identifiers' => 'https://webservices.producaorestrita.esocial.gov.br/servicos/empregador/dwlcirurgico/WsConsultarIdentificadoresEventos.svc',
                'downloads' => 'https://webservices.producaorestrita.esocial.gov.br/servicos/empregador/dwlcirurgico/WsSolicitarDownloadEventos.svc',
            ],
            'production' => [
                'identifiers' => 'https://webservices.download.esocial.gov.br/servicos/empregador/dwlcirurgico/WsConsultarIdentificadoresEventos.svc',
                'downloads' => 'https://webservices.download.esocial.gov.br/servicos/empregador/dwlcirurgico/WsSolicitarDownloadEventos.svc',
            ],
        ],
        'manual_url' => 'https://www.gov.br/esocial/pt-br/documentacao-tecnica/manuais/manualorientacaodesenvolvedoresocialv1-15.pdf',
        'official_announcement_url' => 'https://www.gov.br/esocial/pt-br/noticias/entra-em-operacao-o-esocial-bx-um-baixador-de-arquivos-enviados-ao-sistema',
        'communication_package_url' => 'https://www.gov.br/esocial/pt-br/documentacao-tecnica/manuais/pacote-de-comunicacao-esocial-v1-6.zip',
        'wsdl_sha256' => [
            'identifiers' => '518e5586d28f9be56f4137b6b11ae1a4bf7dc31cbfd979e27a12a49755f1b439',
            'downloads' => '6c5a36c4229e861399bfb6eaf53b2e8babb440b2dfd2d9307180ffbbecec70a2',
        ],
    ],

    /** Kill switch exclusivo do módulo (além de features.modules.fgts). */
    'kill_switch' => filter_var(env('FGTS_ESOCIAL_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),

    /**
     * Janela (horas) após fechamento S-1299 em que a ausência de totalizador
     * ainda é tratada como UNKNOWN (aguardando). Após a janela → ABSENT + finding ATTENTION.
     */
    'totalizer_absence_window_hours' => (int) env('FGTS_ESOCIAL_TOTALIZER_ABSENCE_WINDOW_HOURS', 72),

    'evidence' => [
        'content_type' => 'application/xml',
        'source' => 'esocial_bx',
        'source_version' => env('FGTS_ESOCIAL_SOURCE_VERSION', 'esocial-bx-v1_0_0'),
    ],

    /**
     * Limitações explícitas (texto de produto — API e UI).
     * Nunca omitir: o módulo é parcial por desenho.
     *
     * @var list<string>
     */
    'limitations' => [
        'Cobertura parcial baseada em S-1299 e S-5013 obtidos pelo eSocial BX oficial; S-5003 exige identificação do trabalhador e não é buscado automaticamente.',
        'Não há integração com o portal FGTS Digital: guias, pagamentos e pendências do portal não são consultados.',
        'Fechamento eSocial (S-1299) não equivale a recolhimento de FGTS.',
        'Totalizadores indicam base conhecida; não inferem emissão de guia nem quitação.',
        'Ausência de API M2M oficial mantém estados UNKNOWN ou UNSUPPORTED — sem scraping, Gov.br, CAPTCHA ou cookie.',
        'Divergências alertam apenas inconsistências entre eventos eSocial conhecidos, sem declarar débito do portal.',
    ],

    'coverage_label' => 'FGTS (parcial eSocial)',
    'system_code' => 'ESOCIAL',
    'service_code' => 'FGTS',
    'operation_code' => 'MONITOR',
    'module_key' => 'fgts',
];
