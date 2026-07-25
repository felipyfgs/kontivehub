<?php

/**
 * Agendamento gradual de captura de XML de saída orientado a prazo (SLA operacional).
 * Defaults seguros: planner/dispatch/retry-policy desligados; shadow mode on.
 *
 * @see openspec/changes/schedule-gradual-outbound-xml-capture-by-deadline
 */
return [
    'enabled' => filter_var(env('OUTBOUND_DEADLINE_SCHEDULING_ENABLED', false), FILTER_VALIDATE_BOOL),
    'planner_enabled' => filter_var(env('OUTBOUND_DEADLINE_PLANNER_ENABLED', false), FILTER_VALIDATE_BOOL),
    'dispatch_enabled' => filter_var(env('OUTBOUND_DEADLINE_DISPATCH_ENABLED', false), FILTER_VALIDATE_BOOL),
    /** Planeja e registra sem enfileirar jobs remotos. */
    'shadow_mode' => filter_var(env('OUTBOUND_DEADLINE_SHADOW_MODE', true), FILTER_VALIDATE_BOOL),
    /** Substitui backoff rápido SVRS por no máximo 2 tentativas ≥24h. */
    'deadline_retry_policy' => filter_var(env('OUTBOUND_DEADLINE_RETRY_POLICY', false), FILTER_VALIDATE_BOOL),

    'timezone' => env('OUTBOUND_DEADLINE_TIMEZONE', 'America/Sao_Paulo'),
    /** Dia do mês seguinte para due_at (1 = dia 1). */
    'due_day' => max(1, min(28, (int) env('OUTBOUND_DEADLINE_DUE_DAY', 1))),
    /** Hora local do due (HH:MM:SS). */
    'due_time' => env('OUTBOUND_DEADLINE_DUE_TIME', '23:59:59'),

    /**
     * Buffer interno em horas antes de due_at → target_at.
     * Mínimo 24h enforced em runtime; default 48h.
     */
    'target_buffer_hours' => max(24, (int) env('OUTBOUND_DEADLINE_TARGET_BUFFER_HOURS', 48)),

    /** Acomodação padrão (horas) antes da SVRS. */
    'accommodation_hours' => max(0, (int) env('OUTBOUND_DEADLINE_ACCOMMODATION_HOURS', 24)),
    /** Acomodação mínima quando faltam <7 dias para target_at. */
    'accommodation_short_hours' => max(0, (int) env('OUTBOUND_DEADLINE_ACCOMMODATION_SHORT_HOURS', 6)),
    /** Dias até target_at para encurtar acomodação / ATTENTION. */
    'attention_days' => max(1, (int) env('OUTBOUND_DEADLINE_ATTENTION_DAYS', 7)),
    /** Horas até due_at para CONTINGENCY por janela final. */
    'contingency_hours_before_due' => max(1, (int) env('OUTBOUND_DEADLINE_CONTINGENCY_HOURS', 72)),

    /** Fração da capacidade nominal reservada ao auto-queue (0–1). */
    'auto_queue_capacity_fraction' => min(1.0, max(0.0, (float) env('OUTBOUND_DEADLINE_AUTO_FRACTION', 0.60))),
    /** Limiar de folga (ratio demand/capacity) para ATTENTION por capacidade. */
    'attention_capacity_ratio' => (float) env('OUTBOUND_DEADLINE_ATTENTION_CAPACITY_RATIO', 1.5),

    /** Máximo de transações SVRS externas por chave. */
    'max_svrs_transactions_per_key' => max(1, min(2, (int) env('OUTBOUND_DEADLINE_MAX_TX_PER_KEY', 2))),
    /** Intervalo mínimo entre 1ª e 2ª transação (segundos). */
    'min_hours_between_svrs_attempts' => max(1, (int) env('OUTBOUND_DEADLINE_MIN_HOURS_BETWEEN', 24)),

    /** Exchanges por transação GET+POST. */
    'exchanges_per_transaction' => max(1, (int) env('OUTBOUND_DEADLINE_EXCHANGES_PER_TX', 2)),
    /**
     * Capacidade nominal diária da coorte em exchanges (lido do governador quando existir).
     * Default alinhado ao design: 50 exchanges/dia → 30 no auto-queue (60%).
     */
    'nominal_daily_exchanges' => max(0, (int) env('OUTBOUND_DEADLINE_NOMINAL_DAILY_EXCHANGES', 50)),

    'queue' => env('OUTBOUND_DEADLINE_QUEUE', 'capture-outbound-ma'),
    'planner_batch_size' => max(1, (int) env('OUTBOUND_DEADLINE_PLANNER_BATCH', 500)),
    'dispatch_batch_size' => max(1, (int) env('OUTBOUND_DEADLINE_DISPATCH_BATCH', 20)),
];
