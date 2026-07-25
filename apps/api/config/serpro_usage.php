<?php

use App\Services\Serpro\Usage\UsageLedgerService;

/**
 * Ledger de consumo SERPRO / Integra Contador.
 *
 * Defaults seguros: shadow mode ON e bloqueio comercial OFF até conciliação
 * e evidência formal de precificação. Produção faturável é deny-by-default:
 * shadow, orçamento nulo, preço shadow e fail-open NÃO liberam egress real.
 *
 * @see openspec/specs/serpro-go-live-controlado
 * @see openspec/specs/serpro-operacao-observavel
 * @see UsageLedgerService
 */

return [
    /**
     * Shadow mode: ledger registra reservas/entradas, mas NÃO bloqueia
     * operações por franquia/orçamento (trial e piloto).
     */
    'shadow_mode' => filter_var(env('SERPRO_USAGE_SHADOW_MODE', true), FILTER_VALIDATE_BOOL),

    /**
     * Bloqueio comercial de operações não essenciais quando franquia/limite estoura.
     * Só deve ser true após conciliação e política de plano aprovadas.
     * Shadow mode vence: se shadow=true, blocking fica efetivamente off.
     */
    'commercial_blocking_enabled' => filter_var(
        env('SERPRO_USAGE_COMMERCIAL_BLOCKING', false),
        FILTER_VALIDATE_BOOL
    ),

    /**
     * Exige budgets monetários positivos (global + Office [+ canário]) para
     * chamadas potencialmente faturáveis quando o bloqueio comercial está efetivo.
     */
    'require_positive_monetary_budgets' => filter_var(
        env('SERPRO_USAGE_REQUIRE_MONETARY_BUDGETS', true),
        FILTER_VALIDATE_BOOL
    ),

    /**
     * Em modo produtivo (blocking efetivo), só tabelas com authorizes_production
     * podem precificar/autorizar egress faturável.
     */
    'require_production_price_table' => filter_var(
        env('SERPRO_USAGE_REQUIRE_PRODUCTION_PRICE', true),
        FILTER_VALIDATE_BOOL
    ),

    /**
     * Limiar de alerta de franquia do tenant (0–1). Ex.: 0.8 = 80% da franquia.
     */
    'franchise_alert_threshold' => (float) env('SERPRO_USAGE_FRANCHISE_ALERT_THRESHOLD', 0.8),

    /**
     * Orçamento global mensal da plataforma (unidades faturáveis) — legado quantitativo.
     * Preferir serpro_usage_budgets (MONETARY). null = sem teto quantitativo global.
     */
    'global_monthly_budget' => ($v = env('SERPRO_USAGE_GLOBAL_MONTHLY_BUDGET')) !== null && $v !== ''
        ? (int) $v
        : null,

    /**
     * Fração máxima do orçamento global que um único tenant pode consumir (0–1).
     * Proteção contra tenant ruidoso. null = sem proteção de share.
     */
    'max_tenant_share_of_global' => ($v = env('SERPRO_USAGE_MAX_TENANT_SHARE')) !== null && $v !== ''
        ? (float) $v
        : 0.40,

    /**
     * Moeda de estimativa (apenas metadado; valores em micros).
     */
    'currency' => env('SERPRO_USAGE_CURRENCY', 'BRL'),

    /**
     * Timezone do ciclo de faturamento 21–20.
     */
    'billing_timezone' => env('SERPRO_USAGE_BILLING_TZ', 'America/Sao_Paulo'),

    /**
     * Alertar (audit + log) quando classe DESCONHECIDA for classificada.
     */
    'alert_unknown_class' => filter_var(env('SERPRO_USAGE_ALERT_UNKNOWN', true), FILTER_VALIDATE_BOOL),

    /**
     * Fail-open vs fail-closed quando catálogo/preço indisponível.
     * Default false (deny-by-default). Em shadow mode o ledger ainda permite
     * registrar; o bloqueio comercial efetivo sempre fecha.
     */
    'fail_open_on_unknown' => filter_var(env('SERPRO_USAGE_FAIL_OPEN_UNKNOWN', false), FILTER_VALIDATE_BOOL),

    /**
     * Em egress produtivo, limites de rate 0/ausentes NÃO significam ilimitado.
     */
    'productive_rate_limit_required' => filter_var(
        env('SERPRO_USAGE_PRODUCTIVE_RATE_LIMIT_REQUIRED', true),
        FILTER_VALIDATE_BOOL
    ),

    /**
     * Versão dos limites de rate (invalidação de chaves Redis).
     */
    'rate_limit_version' => (string) env('SERPRO_RATE_LIMIT_VERSION', 'v1'),

    /**
     * Retenção mínima do ledger (dias) — offboarding não apaga antes.
     */
    'ledger_retention_days' => (int) env('SERPRO_USAGE_LEDGER_RETENTION_DAYS', 2555),
];
