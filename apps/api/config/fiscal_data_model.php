<?php

/**
 * Feature flags e política de corte do modelo fiscal consolidado.
 *
 * Leitura default = legado até gate local do agregado. Rollback lógico:
 * desligar `read_canonical` sem apagar escritas novas.
 *
 * @see openspec/changes/consolidate-fiscal-data-model
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

$aggregate = static function (string $envPrefix) use ($parseIdList): array {
    return [
        /** Escritas passam pelo serviço canônico (dual-write opcional no legado). */
        'write_canonical' => filter_var(env("{$envPrefix}_WRITE_CANONICAL", true), FILTER_VALIDATE_BOOL),
        /** Leituras usam autoridade canônica (corte). */
        'read_canonical' => filter_var(env("{$envPrefix}_READ_CANONICAL", false), FILTER_VALIDATE_BOOL),
        /** Compara legado vs canônico em background (shadow). */
        'shadow_verify' => filter_var(env("{$envPrefix}_SHADOW_VERIFY", false), FILTER_VALIDATE_BOOL),
        'office_allowlist' => $parseIdList(env("{$envPrefix}_OFFICE_ALLOWLIST")),
        'allow_all_offices' => filter_var(env("{$envPrefix}_ALLOW_ALL_OFFICES", false), FILTER_VALIDATE_BOOL),
    ];
};

return [
    /**
     * Kill switch: força leitura no legado e desliga shadow de corte.
     * Não apaga dados canônicos já gravados.
     */
    'kill_switch' => filter_var(env('FISCAL_MODEL_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),

    /**
     * Global scope BelongsToOffice fail-closed: sem CurrentOffice e sem
     * PrivilegedOfficeContext, consultas de tenant não retornam linhas.
     * Em phpunit legada pode ser false; testes de isolamento forçam true.
     */
    'fail_closed_scopes' => filter_var(env('FISCAL_MODEL_FAIL_CLOSED_SCOPES', true), FILTER_VALIDATE_BOOL),

    /**
     * Agregados com corte independente (ordem de apply no design).
     */
    'aggregates' => [
        'tenancy_cadastro' => $aggregate('FISCAL_MODEL_TENANCY'),
        'documentos_cursores' => $aggregate('FISCAL_MODEL_DOCUMENTS'),
        'outbound' => $aggregate('FISCAL_MODEL_OUTBOUND'),
        'serpro' => $aggregate('FISCAL_MODEL_SERPRO'),
        'monitoramento_guias' => $aggregate('FISCAL_MODEL_MONITORING'),
    ],

    /**
     * Versão de payload de jobs afetados pelo modelo consolidado.
     * Jobs legados sem versão são drenados ou adaptados antes do corte.
     */
    'job_payload_version' => (int) env('FISCAL_MODEL_JOB_PAYLOAD_VERSION', 1),

    /**
     * Tolerância do reconciliador: divergências não listadas em allowlist falham.
     * Formato env: "aggregate:metric,..." reservado para expansão; default vazio.
     */
    'reconcile_approved_exceptions' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FISCAL_MODEL_RECONCILE_EXCEPTIONS', '')),
    ))),
];
