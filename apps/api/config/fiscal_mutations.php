<?php

use App\Services\Fiscal\Mutations;

/**
 * Operações fiscais mutantes e reconciliação (tasks 13.1–13.8).
 *
 * Defaults seguros: tudo OFF. Liberar por solução/operação/coorte após aprovação.
 *
 * @see Mutations
 * @see openspec/changes/build-complete-fiscal-monitoring-hub (design decisão 10)
 */
$parseIdList = static function (?string $raw): array {
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $ids = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part !== '' && ctype_digit($part)) {
            $ids[] = (int) $part;
        }
    }

    return array_values(array_unique($ids));
};

$operationDefaults = static function (string $envPrefix) use ($parseIdList): array {
    return [
        'enabled' => filter_var(env("{$envPrefix}_ENABLED", false), FILTER_VALIDATE_BOOL),
        'tenant_allowlist' => $parseIdList(env("{$envPrefix}_TENANT_ALLOWLIST")),
        'allow_all_tenants' => filter_var(env("{$envPrefix}_ALLOW_ALL_TENANTS", false), FILTER_VALIDATE_BOOL),
    ];
};

return [
    /**
     * Master switch do subsistema de mutações fiscais (além de features.mutating.*).
     */
    'enabled' => filter_var(env('FISCAL_MUTATIONS_ENABLED', false), FILTER_VALIDATE_BOOL),

    /**
     * Kill switch exclusivo de mutações fiscais (não apaga operações/evidências).
     */
    'kill_switch' => filter_var(env('FISCAL_MUTATIONS_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),

    /**
     * Janela anti-repetição (segundos) para a mesma identidade lógica
     * (tenant+client+solution+service+operation+competence).
     */
    'anti_repeat_window_seconds' => (int) env('FISCAL_MUTATIONS_ANTI_REPEAT_SECONDS', 300),

    /**
     * TTL do preflight (segundos) — confirmação/execução deve ocorrer dentro da janela.
     */
    'preflight_ttl_seconds' => (int) env('FISCAL_MUTATIONS_PREFLIGHT_TTL_SECONDS', 600),

    /**
     * Timeout de transporte considerado resultado incerto (segundos).
     */
    'transport_timeout_seconds' => (int) env('FISCAL_MUTATIONS_TRANSPORT_TIMEOUT', 60),

    /**
     * Ambiente SERPRO default para mutações (TRIAL no piloto).
     */
    'default_environment' => env('FISCAL_MUTATIONS_ENVIRONMENT', 'TRIAL'),

    /**
     * Mapeamento solution_code → módulo FeatureFlags.
     *
     * @var array<string, string>
     */
    'solution_modules' => [
        'PGDASD' => 'simples_mei',
        'PGMEI' => 'simples_mei',
        'DEFIS' => 'simples_mei',
        'DCTFWEB' => 'dctfweb_mit',
        'MIT' => 'dctfweb_mit',
    ],

    /**
     * Coortes por operação (chave SOLUTION.SERVICE.OPERATION).
     * Ausente ou disabled = bloqueado. allowlist vazia + allow_all=false = ninguém.
     *
     * @var array<string, array{enabled: bool, tenant_allowlist: list<int>, allow_all_tenants: bool}>
     */
    'operations' => [
        // Central de declarações — coordenadas oficiais; todas OFF por default.
        'PGDASD.PGDASD.TRANSDECLARACAO11' => $operationDefaults('FEATURE_MUT_DECL_PGDASD_TRANSMITIR'),
        'PGDASD.PGDASD.GERARDAS12' => $operationDefaults('FEATURE_MUT_DECL_PGDASD_GERAR_DAS'),
        'PGDASD.PGDASD.GERARDASCOBRANCA17' => $operationDefaults('FEATURE_MUT_DECL_PGDASD_DAS_COBRANCA'),
        'PGDASD.PGDASD.GERARDASPROCESSO18' => $operationDefaults('FEATURE_MUT_DECL_PGDASD_DAS_PROCESSO'),
        'PGDASD.PGDASD.GERARDASAVULSO19' => $operationDefaults('FEATURE_MUT_DECL_PGDASD_DAS_AVULSO'),
        'PGMEI.PGMEI.GERARDASPDF21' => $operationDefaults('FEATURE_MUT_PGMEI_GERAR_DAS_PDF'),
        'PGMEI.PGMEI.GERARDASCODBARRA22' => $operationDefaults('FEATURE_MUT_PGMEI_GERAR_DAS_COD_BARRA'),
        'DEFIS.DEFIS.TRANSDECLARACAO141' => $operationDefaults('FEATURE_MUT_DECL_DEFIS_TRANSMITIR'),
        'DCTFWEB.DCTFWEB.GERARGUIA31' => $operationDefaults('FEATURE_MUT_DECL_DCTFWEB_GERAR_GUIA'),
        'DCTFWEB.DCTFWEB.TRANSDECLARACAO310' => $operationDefaults('FEATURE_MUT_DECL_DCTFWEB_TRANSMITIR'),
        'DCTFWEB.DCTFWEB.GERARGUIAANDAMENTO313' => $operationDefaults('FEATURE_MUT_DECL_DCTFWEB_GUIA_ANDAMENTO'),
        'MIT.MIT.ENCAPURACAO314' => $operationDefaults('FEATURE_MUT_DECL_MIT_ENCERRAR'),

    ],

    'queue' => env('FISCAL_MUTATIONS_QUEUE', 'default'),
];
