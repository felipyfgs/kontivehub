<?php

/**
 * Captura SEFAZ DistDFe / CT-e — flags default off até smoke.
 *
 * @see openspec/changes/capture-multi-dfe-sefaz
 */
return [
    /**
     * Bundle CA adicional (ICP-Brasil / intermediários SEFAZ).
     * Necessário quando o SO não confia na cadeia SERPRO/ICP-Brasil.
     */
    'ca_bundle' => env(
        'SEFAZ_CA_BUNDLE',
        storage_path('app/certs/sefaz-ca-bundle.pem')
    ),

    // Feature flags (default off)
    'distdfe_enabled' => filter_var(env('SEFAZ_DISTDFE_ENABLED', false), FILTER_VALIDATE_BOOL),
    // MD-e manual/conclusiva (UI). Ciência automática usa auto_ciencia_enabled.
    'manifest_enabled' => filter_var(env('SEFAZ_MANIFEST_ENABLED', false), FILTER_VALIDATE_BOOL),
    /**
     * Ciência técnica automática (210210) ao capturar resNFe sem procNFe.
     * Objetivo: desbloquear XML completo na reconsulta DistDFe — NÃO confirma a operação.
     * Default on quando DistDFe está no ar; desligue com SEFAZ_AUTO_CIENCIA_ENABLED=false.
     */
    'auto_ciencia_enabled' => filter_var(
        env('SEFAZ_AUTO_CIENCIA_ENABLED', env('SEFAZ_DISTDFE_ENABLED', false)),
        FILTER_VALIDATE_BOOL
    ),
    /** Espaçamento entre jobs de ciência (rate limit RecepcaoEvento). */
    'auto_ciencia_delay_seconds' => (int) env('SEFAZ_AUTO_CIENCIA_DELAY_SECONDS', 3),
    /** Teto de ciências enfileiradas por página DistDFe processada. */
    'auto_ciencia_max_per_page' => (int) env('SEFAZ_AUTO_CIENCIA_MAX_PER_PAGE', 30),
    'cte_enabled' => filter_var(env('SEFAZ_CTE_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Compatibilidade legada: MDF-e está fora do escopo escritural e não pode ser reativado por env.
    'mdfe_enabled' => false,
    // NFC-e: gap documentado — só habilitar se canal real existir
    'nfce_enabled' => filter_var(env('SEFAZ_NFCE_ENABLED', false), FILTER_VALIDATE_BOOL),

    'environment' => env('SEFAZ_ENVIRONMENT', 'production'), // production | homologation
    // cUFAutor padrão quando o cadastro do estabelecimento não tem UF
    'default_cuf_autor' => env('SEFAZ_DEFAULT_CUF_AUTOR', '35'),

    'timeout_seconds' => (int) env('SEFAZ_TIMEOUT_SECONDS', 60),
    'connect_timeout_seconds' => (int) env('SEFAZ_CONNECT_TIMEOUT_SECONDS', 15),
    'verify_tls' => filter_var(env('SEFAZ_VERIFY_TLS', true), FILTER_VALIDATE_BOOL),

    // Rate limit operacional (NT 2014.002 / práticas de mercado)
    'page_sleep_seconds' => (float) env('SEFAZ_PAGE_SLEEP_SECONDS', 2),
    'quiet_hours_after_empty' => (float) env('SEFAZ_QUIET_HOURS_AFTER_EMPTY', 1),
    'max_pages_per_job' => (int) env('SEFAZ_MAX_PAGES_PER_JOB', 12),
    'decode_failure_threshold' => (int) env('SEFAZ_DECODE_FAILURE_THRESHOLD', 5),
    'job_timeout_seconds' => (int) env('SEFAZ_JOB_TIMEOUT_SECONDS', 900),
    'lock_ttl_seconds' => (int) env('SEFAZ_LOCK_TTL_SECONDS', 960),

    'queues' => [
        'nfe' => env('SEFAZ_QUEUE_NFE', 'sync-sefaz-nfe'),
        'manifest' => env('SEFAZ_QUEUE_MANIFEST', 'manifest-nfe'),
        'cte' => env('SEFAZ_QUEUE_CTE', 'sync-sefaz-cte'),
    ],

    // URLs Ambiente Nacional (confirmar na relação oficial de WS)
    'nfe' => [
        'production' => env(
            'SEFAZ_NFE_DISTDFE_URL',
            'https://www1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx'
        ),
        'homologation' => env(
            'SEFAZ_NFE_DISTDFE_URL_HOM',
            'https://hom.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx'
        ),
        'soap_action' => 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe/nfeDistDFeInteresse',
        'namespace' => 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe',
        'layout_version' => '1.01',
    ],

    'cte' => [
        'require_signature' => filter_var(env('SEFAZ_CTE_REQUIRE_SIGNATURE', true), FILTER_VALIDATE_BOOL),
        'production' => env(
            'SEFAZ_CTE_DISTDFE_URL',
            'https://www1.cte.fazenda.gov.br/CTeDistribuicaoDFe/CTeDistribuicaoDFe.asmx'
        ),
        'homologation' => env(
            'SEFAZ_CTE_DISTDFE_URL_HOM',
            'https://hom1.cte.fazenda.gov.br/CTeDistribuicaoDFe/CTeDistribuicaoDFe.asmx'
        ),
        'soap_action' => env(
            'SEFAZ_CTE_SOAP_ACTION',
            'http://www.portalfiscal.inf.br/cte/wsdl/CTeDistribuicaoDFe/cteDistDFeInteresse'
        ),
        'namespace' => env(
            'SEFAZ_CTE_NAMESPACE',
            'http://www.portalfiscal.inf.br/cte/wsdl/CTeDistribuicaoDFe'
        ),
        'layout_version' => env('SEFAZ_CTE_LAYOUT_VERSION', '1.00'),
    ],

    // Bloco de endpoints MDF-e removido: capability fora do catálogo escritural
    // (mdfe_enabled=false; enums/cursors legados permanecem para leitura).

    'manifest' => [
        'production' => env(
            'SEFAZ_NFE_EVENTO_URL',
            'https://www.nfe.fazenda.gov.br/NFeRecepcaoEvento4/NFeRecepcaoEvento4.asmx'
        ),
        'homologation' => env(
            'SEFAZ_NFE_EVENTO_URL_HOM',
            'https://hom.nfe.fazenda.gov.br/NFeRecepcaoEvento4/NFeRecepcaoEvento4.asmx'
        ),
        'soap_action' => env(
            'SEFAZ_NFE_EVENTO_SOAP_ACTION',
            'http://www.portalfiscal.inf.br/nfe/wsdl/NFeRecepcaoEvento4/nfeRecepcaoEvento'
        ),
        'namespace' => env(
            'SEFAZ_NFE_EVENTO_NS',
            'http://www.portalfiscal.inf.br/nfe/wsdl/NFeRecepcaoEvento4'
        ),
    ],

    /**
     * Captura de saídas MA (NF-e 55 / NFC-e 65) — flags default off até gates G1–G5.
     *
     * @see openspec/changes/build-ma-outbound-nfe-nfce-capture
     * @see docs/ops/ma-outbound-g0-decision.md
     */
    'ma_outbound' => [
        // Feature flags (todas false por padrão — G0)
        'enabled' => filter_var(env('SEFAZ_MA_OUTBOUND_ENABLED', false), FILTER_VALIDATE_BOOL),
        'protocol_query_enabled' => filter_var(env('SEFAZ_MA_PROTOCOL_QUERY_ENABLED', false), FILTER_VALIDATE_BOOL),
        'm2m_retrieval_enabled' => filter_var(env('SEFAZ_MA_M2M_RETRIEVAL_ENABLED', false), FILTER_VALIDATE_BOOL),
        'mutating_probe_enabled' => filter_var(env('SEFAZ_MA_MUTATING_PROBE_ENABLED', false), FILTER_VALIDATE_BOOL),

        // Kill switch operacional (não apaga estado/XML)
        'kill_switch' => filter_var(env('SEFAZ_MA_OUTBOUND_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),

        // UF e cUF do piloto
        'uf' => 'MA',
        'cuf' => '21',

        // Limites conservadores (não ampliar sem nova validação operacional)
        'global_rps' => (float) env('SEFAZ_MA_OUTBOUND_RPS', 1),
        'max_numbers_per_run' => (int) env('SEFAZ_MA_OUTBOUND_MAX_NUMBERS_PER_RUN', 10),
        'retry_interval_hours' => (int) env('SEFAZ_MA_OUTBOUND_RETRY_HOURS', 12),
        'max_attempts_per_number' => (int) env('SEFAZ_MA_OUTBOUND_MAX_ATTEMPTS', 10),
        'seed_max_age_days' => (int) env('SEFAZ_MA_OUTBOUND_SEED_MAX_AGE_DAYS', 60),
        'lock_ttl_seconds' => (int) env('SEFAZ_MA_OUTBOUND_LOCK_TTL', 960),
        'job_timeout_seconds' => (int) env('SEFAZ_MA_OUTBOUND_JOB_TIMEOUT', 900),

        'queue' => env('SEFAZ_MA_OUTBOUND_QUEUE', 'capture-outbound-ma'),

        // Schemas / NT de referência (versionados — não inventar leiaute)
        'schemas' => [
            'consulta_protocolo' => 'NFeConsultaProtocolo4',
            'moc_cstat_562' => '562',
            'moc_cstat_539' => '539',
            'nfe_layout' => '4.00',
            'alphanumeric_key_nt' => 'NT2025.001', // CNPJ/chave alfanuméricos — leiaute vigente
        ],

        /**
         * Endpoints oficiais de consulta de protocolo por modelo e ambiente.
         * Modelo 55/MA → SVAN; modelo 65/MA → SVRS.
         * URLs padrão alinhadas à relação de Web Services do portal NF-e / SVRS.
         */
        'consulta_protocolo' => [
            '55' => [
                'authorizer' => 'SVAN',
                'production' => env(
                    'SEFAZ_MA_NFE55_CONSULTA_URL',
                    'https://www.sefazvirtual.fazenda.gov.br/NFeConsultaProtocolo4/NFeConsultaProtocolo4.asmx'
                ),
                'homologation' => env(
                    'SEFAZ_MA_NFE55_CONSULTA_URL_HOM',
                    'https://hom.sefazvirtual.fazenda.gov.br/NFeConsultaProtocolo4/NFeConsultaProtocolo4.asmx'
                ),
                'soap_action' => env(
                    'SEFAZ_MA_NFE55_CONSULTA_SOAP_ACTION',
                    'http://www.portalfiscal.inf.br/nfe/wsdl/NFeConsultaProtocolo4/nfeConsultaNF'
                ),
                'namespace' => env(
                    'SEFAZ_MA_NFE55_CONSULTA_NS',
                    'http://www.portalfiscal.inf.br/nfe/wsdl/NFeConsultaProtocolo4'
                ),
            ],
            '65' => [
                'authorizer' => 'SVRS',
                'production' => env(
                    'SEFAZ_MA_NFCE65_CONSULTA_URL',
                    'https://nfce.svrs.rs.gov.br/ws/NfeConsulta/NfeConsulta4.asmx'
                ),
                'homologation' => env(
                    'SEFAZ_MA_NFCE65_CONSULTA_URL_HOM',
                    'https://nfce-homologacao.svrs.rs.gov.br/ws/NfeConsulta/NfeConsulta4.asmx'
                ),
                'soap_action' => env(
                    'SEFAZ_MA_NFCE65_CONSULTA_SOAP_ACTION',
                    'http://www.portalfiscal.inf.br/nfe/wsdl/NFeConsultaProtocolo4/nfeConsultaNF'
                ),
                'namespace' => env(
                    'SEFAZ_MA_NFCE65_CONSULTA_NS',
                    'http://www.portalfiscal.inf.br/nfe/wsdl/NFeConsultaProtocolo4'
                ),
            ],
        ],

        // Status M2M: NO_GO até contrato formal da SEFAZ-MA
        'm2m_status' => env('SEFAZ_MA_M2M_STATUS', 'NO_GO_M2M'),
    ],

    /**
     * Governador compartilhado de egress do portal SVRS (NF-e 55 + NFC-e 65).
     * Budgets PREVENTIVOS — não são limites oficiais do NFESSL/NFCESSL.
     * Master/auto-queue desligados por padrão; sem override por request de API.
     *
     * @see openspec/changes/add-resilient-svrs-nfe55-outbound-xml-retrieval design D3
     * @see docs/adr/004-distdfe-vs-nfessl-limits.md
     */
    'svrs_portal_egress' => [
        'cohort_id' => env('SVRS_EGRESS_COHORT_ID', 'default'),
        // true = permite apenas uma implantação ativa na coorte sem coordenador Redis compartilhado documentado
        'require_shared_coordinator' => filter_var(env('SVRS_EGRESS_REQUIRE_SHARED_COORDINATOR', true), FILTER_VALIDATE_BOOL),
        'deployment_id' => env('SVRS_EGRESS_DEPLOYMENT_ID', env('HOSTNAME', 'local')),
        'host' => env('SEFAZ_SVRS_PORTAL_HOST', 'dfe-portal.svrs.rs.gov.br'),
        'allowed_hosts' => array_values(array_filter(array_map(
            static fn (string $h): string => strtolower(trim($h)),
            explode(',', (string) env(
                'SEFAZ_SVRS_PORTAL_ALLOWED_HOSTS',
                'dfe-portal.svrs.rs.gov.br'
            ))
        ), static fn (string $h): bool => $h !== '')),

        // Uma transação lógica em voo; GET+POST = 2 exchanges reservados atomicamente
        'max_inflight_transactions' => (int) env('SVRS_EGRESS_MAX_INFLIGHT', 1),
        'exchanges_per_download' => (int) env('SVRS_EGRESS_EXCHANGES_PER_DOWNLOAD', 2),
        'min_interval_global_seconds' => (float) env('SVRS_EGRESS_MIN_INTERVAL_GLOBAL', 120),
        'min_interval_root_seconds' => (float) env('SVRS_EGRESS_MIN_INTERVAL_ROOT', 900), // 15 min
        'max_exchanges_per_hour' => (int) env('SVRS_EGRESS_MAX_EXCHANGES_HOUR', 10),
        'max_exchanges_per_day' => (int) env('SVRS_EGRESS_MAX_EXCHANGES_DAY', 50),
        'max_keys_per_root_per_day' => (int) env('SVRS_EGRESS_MAX_KEYS_ROOT_DAY', 6),
        'max_keys_per_job' => (int) env('SVRS_EGRESS_MAX_KEYS_PER_JOB', 1),
        'retry_jitter_ratio' => (float) env('SVRS_EGRESS_RETRY_JITTER', 0.1),

        // Cooldown bloqueio múltiplas consultas (segundos): 24h, 48h, 96h, 168h
        'block_cooldown_ladder_seconds' => [86400, 172800, 345600, 604800],
        'canary_only_after_block' => true,

        'lock_ttl_seconds' => (int) env('SVRS_EGRESS_LOCK_TTL', 180),
        'reservation_ttl_seconds' => (int) env('SVRS_EGRESS_RESERVATION_TTL', 120),
    ],

    /**
     * Canal SVRS — recuperação de nfeProc de NFC-e 65 por chave + A1 (portal oficial).
     * Defaults off; hosts/paths allowlisted; sem override por request de API.
     * Taxa/orçamento: governador compartilhado (svrs_portal_egress) — não 5s/30s/20.
     *
     * @see openspec/changes/add-svrs-nfce-outbound-xml-retrieval
     * @see openspec/changes/add-resilient-svrs-nfe55-outbound-xml-retrieval
     * @see docs/ops/svrs-nfce-enablement-matrix.md
     */
    'svrs_nfce_xml' => [
        // Feature flags (todas false por padrão)
        'retrieval_enabled' => filter_var(env('SEFAZ_SVRS_NFCE_XML_RETRIEVAL_ENABLED', false), FILTER_VALIDATE_BOOL),
        'auto_queue_enabled' => filter_var(env('SEFAZ_SVRS_NFCE_XML_AUTO_QUEUE_ENABLED', false), FILTER_VALIDATE_BOOL),
        'pilot_allowlist_only' => filter_var(env('SEFAZ_SVRS_NFCE_XML_PILOT_ALLOWLIST_ONLY', false), FILTER_VALIDATE_BOOL),

        // Kill switch operacional (não apaga estado/XML/tentativas)
        'kill_switch' => filter_var(env('SEFAZ_SVRS_NFCE_XML_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),

        // Host e paths HTTPS allowlisted (sem override por request)
        'scheme' => 'https',
        'host' => env('SEFAZ_SVRS_NFCE_XML_HOST', env('SEFAZ_SVRS_PORTAL_HOST', 'dfe-portal.svrs.rs.gov.br')),
        'allowed_hosts' => array_values(array_filter(array_map(
            static fn (string $h): string => strtolower(trim($h)),
            explode(',', (string) env(
                'SEFAZ_SVRS_NFCE_XML_ALLOWED_HOSTS',
                env('SEFAZ_SVRS_PORTAL_ALLOWED_HOSTS', 'dfe-portal.svrs.rs.gov.br')
            ))
        ), static fn (string $h): bool => $h !== '')),
        'get_path' => env('SEFAZ_SVRS_NFCE_XML_GET_PATH', '/NFCESSL/DownloadXMLDFe'),
        'post_path' => env('SEFAZ_SVRS_NFCE_XML_POST_PATH', '/NfceSSL/DownloadXmlDfe'),
        'min_tls_version' => '1.2',
        'verify_tls' => true, // nunca desligável via env de request
        'verify_hostname' => true,

        // Timeouts e limites de payload
        'timeout_seconds' => (int) env('SEFAZ_SVRS_NFCE_XML_TIMEOUT_SECONDS', 30),
        'connect_timeout_seconds' => (int) env('SEFAZ_SVRS_NFCE_XML_CONNECT_TIMEOUT_SECONDS', 10),
        'max_html_bytes' => (int) env('SEFAZ_SVRS_NFCE_XML_MAX_HTML_BYTES', 524288), // 512 KiB
        'max_literal_bytes' => (int) env('SEFAZ_SVRS_NFCE_XML_MAX_LITERAL_BYTES', 262144), // 256 KiB
        'max_xml_bytes' => (int) env('SEFAZ_SVRS_NFCE_XML_MAX_XML_BYTES', 262144),

        // Rate limit / batch — DELEGADOS ao governador; defaults defensivos (não 5/30/20)
        'max_inflight_global' => (int) env('SEFAZ_SVRS_NFCE_XML_MAX_INFLIGHT', env('SVRS_EGRESS_MAX_INFLIGHT', 1)),
        'min_interval_global_seconds' => (float) env('SEFAZ_SVRS_NFCE_XML_MIN_INTERVAL_GLOBAL', env('SVRS_EGRESS_MIN_INTERVAL_GLOBAL', 120)),
        'min_interval_root_seconds' => (float) env('SEFAZ_SVRS_NFCE_XML_MIN_INTERVAL_ROOT', env('SVRS_EGRESS_MIN_INTERVAL_ROOT', 900)),
        'max_keys_per_run' => (int) env('SEFAZ_SVRS_NFCE_XML_MAX_KEYS_PER_RUN', env('SVRS_EGRESS_MAX_KEYS_PER_JOB', 1)),
        'max_recoverable_attempts' => (int) env('SEFAZ_SVRS_NFCE_XML_MAX_ATTEMPTS', 5),
        // Backoff em segundos: 15m, 1h, 6h, 12h
        'retry_backoff_seconds' => [900, 3600, 21600, 43200],
        'retry_jitter_ratio' => (float) env('SEFAZ_SVRS_NFCE_XML_RETRY_JITTER', env('SVRS_EGRESS_RETRY_JITTER', 0.1)),

        // Circuit breaker (cache legado; coorte durável no governador)
        'breaker_open_seconds' => (int) env('SEFAZ_SVRS_NFCE_XML_BREAKER_OPEN_SECONDS', 86400),
        'breaker_failure_threshold' => (int) env('SEFAZ_SVRS_NFCE_XML_BREAKER_THRESHOLD', 3),

        'queue' => env('SEFAZ_SVRS_NFCE_XML_QUEUE', env('SEFAZ_MA_OUTBOUND_QUEUE', 'capture-outbound-ma')),
        'job_timeout_seconds' => (int) env('SEFAZ_SVRS_NFCE_XML_JOB_TIMEOUT', 120),
        'lock_ttl_seconds' => (int) env('SEFAZ_SVRS_NFCE_XML_LOCK_TTL', 180),

        // Parser versionado (bump quando fixture/contrato muda de forma compatível)
        'wrapper_parser_version' => env('SEFAZ_SVRS_NFCE_XML_PARSER_VERSION', '2'),
        // Exigir XMLDSig em produção; em testing fixtures sem Signature são aceitas se false
        'require_signature' => filter_var(env('SEFAZ_SVRS_NFCE_XML_REQUIRE_SIGNATURE', true), FILTER_VALIDATE_BOOL),

        // Campos oficiais do POST (imutáveis por request)
        'post_fields' => [
            'sistema' => 'Nfce',
            'OrigemSite' => '0',
            // Ambiente e ChaveAcessoDfe preenchidos em runtime
        ],
    ],

    /**
     * Canal SVRS — recuperação de nfeProc de NF-e 55 por chave + A1 (portal NFESSL).
     * Defaults off; orçamento no governador compartilhado.
     *
     * @see openspec/changes/add-resilient-svrs-nfe55-outbound-xml-retrieval
     */
    'svrs_nfe55_xml' => [
        'retrieval_enabled' => filter_var(env('SEFAZ_SVRS_NFE55_XML_RETRIEVAL_ENABLED', false), FILTER_VALIDATE_BOOL),
        'auto_queue_enabled' => filter_var(env('SEFAZ_SVRS_NFE55_XML_AUTO_QUEUE_ENABLED', false), FILTER_VALIDATE_BOOL),
        'pilot_allowlist_only' => filter_var(env('SEFAZ_SVRS_NFE55_XML_PILOT_ALLOWLIST_ONLY', false), FILTER_VALIDATE_BOOL),
        'kill_switch' => filter_var(env('SEFAZ_SVRS_NFE55_XML_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),

        'scheme' => 'https',
        'host' => env('SEFAZ_SVRS_NFE55_XML_HOST', env('SEFAZ_SVRS_PORTAL_HOST', 'dfe-portal.svrs.rs.gov.br')),
        'port' => env('SEFAZ_SVRS_NFE55_XML_PORT'),
        'allowed_hosts' => array_values(array_filter(array_map(
            static fn (string $h): string => strtolower(trim($h)),
            explode(',', (string) env(
                'SEFAZ_SVRS_NFE55_XML_ALLOWED_HOSTS',
                env('SEFAZ_SVRS_PORTAL_ALLOWED_HOSTS', 'dfe-portal.svrs.rs.gov.br')
            ))
        ), static fn (string $h): bool => $h !== '')),
        'get_path' => env('SEFAZ_SVRS_NFE55_XML_GET_PATH', '/NFESSL/DownloadXMLDFe'),
        'post_path' => env('SEFAZ_SVRS_NFE55_XML_POST_PATH', '/NfeSSL/DownloadXmlDfe'),
        'min_tls_version' => '1.2',
        'verify_tls' => true,
        'verify_hostname' => true,

        'timeout_seconds' => (int) env('SEFAZ_SVRS_NFE55_XML_TIMEOUT_SECONDS', 30),
        'connect_timeout_seconds' => (int) env('SEFAZ_SVRS_NFE55_XML_CONNECT_TIMEOUT_SECONDS', 10),
        'max_html_bytes' => (int) env('SEFAZ_SVRS_NFE55_XML_MAX_HTML_BYTES', 524288),
        'max_literal_bytes' => (int) env('SEFAZ_SVRS_NFE55_XML_MAX_LITERAL_BYTES', 262144),
        'max_xml_bytes' => (int) env('SEFAZ_SVRS_NFE55_XML_MAX_XML_BYTES', 262144),

        'max_recoverable_attempts' => (int) env('SEFAZ_SVRS_NFE55_XML_MAX_ATTEMPTS', 5),
        'retry_backoff_seconds' => [900, 3600, 21600, 43200],
        'retry_jitter_ratio' => (float) env('SEFAZ_SVRS_NFE55_XML_RETRY_JITTER', 0.1),

        'queue' => env('SEFAZ_SVRS_NFE55_XML_QUEUE', env('SEFAZ_MA_OUTBOUND_QUEUE', 'capture-outbound-ma')),
        'job_timeout_seconds' => (int) env('SEFAZ_SVRS_NFE55_XML_JOB_TIMEOUT', 120),
        'lock_ttl_seconds' => (int) env('SEFAZ_SVRS_NFE55_XML_LOCK_TTL', 180),
        'wrapper_parser_version' => env('SEFAZ_SVRS_NFE55_XML_PARSER_VERSION', '2'),
        'require_signature' => filter_var(env('SEFAZ_SVRS_NFE55_XML_REQUIRE_SIGNATURE', true), FILTER_VALIDATE_BOOL),

        'post_fields' => [
            'sistema' => 'Nfe',
            'OrigemSite' => '0',
        ],
    ],

    /**
     * Canal NFE_AUTXML_DISTDFE — escritório como terceiro em autXML.
     * Default off; allowlist vazia; kill switch não apaga cursor/XML.
     *
     * @see openspec/changes/add-office-autxml-and-bulk-xml-import
     * @see docs/ops/autxml-external-distnsu-consumers.md
     */
    'autxml' => [
        'enabled' => filter_var(env('SEFAZ_AUTXML_DISTDFE_ENABLED', false), FILTER_VALIDATE_BOOL),
        'kill_switch' => filter_var(env('SEFAZ_AUTXML_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),
        /**
         * Lista opcional de office_id permitidos no piloto.
         * Vazia = nenhum office, mesmo com enabled=true (exceto se allowlist for desligada explicitamente).
         */
        'office_allowlist' => array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', (string) env('SEFAZ_AUTXML_OFFICE_ALLOWLIST', ''))
        ), static fn (int $id): bool => $id > 0)),
        /**
         * Quando true e allowlist vazia, qualquer office é elegível (somente após gates de piloto).
         * Default false: allowlist vazia bloqueia todos.
         */
        'allow_all_offices' => filter_var(env('SEFAZ_AUTXML_ALLOW_ALL_OFFICES', false), FILTER_VALIDATE_BOOL),
        'queue' => env('SEFAZ_AUTXML_QUEUE', 'sync-sefaz-autxml'),
        'max_pages_per_job' => (int) env('SEFAZ_AUTXML_MAX_PAGES_PER_JOB', 20),
        'page_sleep_seconds' => (float) env('SEFAZ_AUTXML_PAGE_SLEEP_SECONDS', 2),
        'quiet_hours_after_empty' => (float) env('SEFAZ_AUTXML_QUIET_HOURS', 1),
        /** Cooldown mínimo após cStat 656 (horas). */
        'circuit_breaker_hours' => (float) env('SEFAZ_AUTXML_CIRCUIT_BREAKER_HOURS', 1),
        'decode_failure_threshold' => (int) env('SEFAZ_AUTXML_DECODE_FAILURE_THRESHOLD', 5),
        'job_timeout_seconds' => (int) env('SEFAZ_AUTXML_JOB_TIMEOUT_SECONDS', 900),
        'lock_ttl_seconds' => (int) env('SEFAZ_AUTXML_LOCK_TTL_SECONDS', 960),
    ],

    /**
     * Canal CT-e autXML do escritório (CTeDistribuicaoDFe com A1 do office).
     * Default off; reutiliza identidade/credencial do office (finalidade NFE_AUTXML_DISTDFE).
     *
     * @see openspec/changes/complete-cte-capture-with-distdfe-autxml-and-import
     */
    'cte_autxml' => [
        'enabled' => filter_var(env('SEFAZ_CTE_AUTXML_DISTDFE_ENABLED', false), FILTER_VALIDATE_BOOL),
        'kill_switch' => filter_var(env('SEFAZ_CTE_AUTXML_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),
        'office_allowlist' => array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', (string) env('SEFAZ_CTE_AUTXML_OFFICE_ALLOWLIST', ''))
        ), static fn (int $id): bool => $id > 0)),
        'allow_all_offices' => filter_var(env('SEFAZ_CTE_AUTXML_ALLOW_ALL_OFFICES', false), FILTER_VALIDATE_BOOL),
        'queue' => env('SEFAZ_CTE_AUTXML_QUEUE', 'sync-sefaz-cte-autxml'),
        'max_pages_per_job' => (int) env('SEFAZ_CTE_AUTXML_MAX_PAGES_PER_JOB', 20),
        'page_sleep_seconds' => (float) env('SEFAZ_CTE_AUTXML_PAGE_SLEEP_SECONDS', 2),
        'quiet_hours_after_empty' => (float) env('SEFAZ_CTE_AUTXML_QUIET_HOURS', 1),
        'circuit_breaker_hours' => (float) env('SEFAZ_CTE_AUTXML_CIRCUIT_BREAKER_HOURS', 1),
        'decode_failure_threshold' => (int) env('SEFAZ_CTE_AUTXML_DECODE_FAILURE_THRESHOLD', 5),
        'job_timeout_seconds' => (int) env('SEFAZ_CTE_AUTXML_JOB_TIMEOUT_SECONDS', 900),
        'lock_ttl_seconds' => (int) env('SEFAZ_CTE_AUTXML_LOCK_TTL_SECONDS', 960),
        /** Orçamento conservador de consNSU por job (reparo de NSU conhecido). */
        'cons_nsu_budget_per_job' => (int) env('SEFAZ_CTE_CONS_NSU_BUDGET_PER_JOB', 3),
    ],

    /** Entrega autenticada de CT-e pelo emissor (token hash, escopo cte:ingest). */
    'cte_emitter_push' => [
        'enabled' => filter_var(env('SEFAZ_CTE_EMITTER_PUSH_ENABLED', false), FILTER_VALIDATE_BOOL),
        'rate_limit_per_minute' => (int) env('SEFAZ_CTE_EMITTER_PUSH_RATE_LIMIT', 30),
        /** Issue/revoke de token de integração (ADMIN) — mais restrito que o push público. */
        'admin_token_rate_limit_per_minute' => (int) env('SEFAZ_CTE_EMITTER_PUSH_ADMIN_TOKEN_RATE_LIMIT', 10),
        'max_payload_bytes' => (int) env('SEFAZ_CTE_EMITTER_PUSH_MAX_BYTES', 5_242_880), // 5 MiB
    ],
];
