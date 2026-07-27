<?php

/**
 * Central de guias fiscais (tax-guide-management).
 * Mutações OFF por default — FeatureFlags::isMutatingEnabled('guides').
 */
return [
    'enabled' => filter_var(env('TAX_GUIDES_ENABLED', false), FILTER_VALIDATE_BOOL),

    'download' => [
        /** TTL do token de download temporário (segundos). */
        'token_ttl_seconds' => (int) env('TAX_GUIDES_DOWNLOAD_TTL', 120),
        /** Máximo de bytes por documento de guia no cofre. */
        'max_bytes' => (int) env('TAX_GUIDES_MAX_BYTES', 5_242_880),
        /** Retenção de artefato no cofre (dias). */
        'retention_days' => (int) env('TAX_GUIDES_RETENTION_DAYS', 2555),
    ],

    'issuance' => [
        /** Janela de reutilização de guia vigente (sem reemissão). */
        'reuse_valid_guide' => true,
        /** Segundos para bloquear retry após UNKNOWN_RESULT antes de reconciliar. */
        'unknown_result_retry_block_seconds' => (int) env('TAX_GUIDES_UNKNOWN_BLOCK_SECONDS', 300),
        /** Delay padrão antes da 1ª reconciliação após timeout. */
        'reconcile_after_seconds' => (int) env('TAX_GUIDES_RECONCILE_AFTER', 60),
        /** Máximo de tentativas de reconciliação automática. */
        'max_reconcile_attempts' => (int) env('TAX_GUIDES_MAX_RECONCILE', 10),
    ],

    'high_risk' => [
        /** Operações EMITIR_* com valor acima deste limiar (centavos) são HIGH. 0 = sempre HIGH se mutante. */
        'amount_threshold_cents' => (int) env('TAX_GUIDES_HIGH_RISK_CENTS', 0),
        /** Janela de reconfirmação de senha (segundos). */
        'challenge_window_seconds' => (int) env('TAX_GUIDES_CHALLENGE_WINDOW', 300),
        /** Papel mínimo para emissão mutante. */
        'required_role' => 'tenant_admin',
    ],

    /**
     * Catálogo local de operações de guia suportadas (além do catálogo SERPRO).
     * operation_key|system|service|operation|risk|label
     *
     * @var list<array{operation_key:string,system:string,service:string,operation:string,risk:string,label:string}>
     */
    'operations' => [
        [
            'operation_key' => 'sicalc.consolidargerardarf',
            'system' => 'INTEGRA_PAGAMENTO',
            'service' => 'SICALC',
            'operation' => 'EMITIR_GUIA',
            'risk' => 'HIGH',
            'label' => 'Emitir guia Sicalc',
        ],
        [
            'operation_key' => 'pagtoweb.pagamentos',
            'system' => 'INTEGRA_PAGAMENTO',
            'service' => 'SICALC',
            'operation' => 'CONSULTAR_PAGAMENTO',
            'risk' => 'STANDARD',
            'label' => 'Consultar pagamento oficial',
        ],
    ],
];
