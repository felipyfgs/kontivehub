<?php

use Illuminate\Support\Facades\Schedule;

/**
 * Classificação de schedules (Lote 11):
 * - dispatch/due/everyMinute: overlap + singleton multi-réplica (externo ou mutável)
 * - hourly maintenance: overlap + singleton
 * - daily scans/gc/backup: overlap + singleton
 * - heartbeat: sem lock agressivo (readiness leve)
 */
$lock = static function ($event, int $expiresAt = 10) {
    return $event
        ->withoutOverlapping($expiresAt, releaseOnTerminationSignals: true)
        ->onOneServer();
};

// Heartbeat leve do scheduler (readiness de produção; sem integração externa).
Schedule::command('ops:scheduler-heartbeat')->everyMinute();

$lock(Schedule::command('adn:dispatch-due-syncs')->everyMinute());
$lock(Schedule::command('sefaz:dispatch-due-syncs')->everyMinute());
$lock(Schedule::command('sefaz:dispatch-due-autxml')->everyMinute());
$lock(Schedule::command('sefaz:dispatch-due-cte-autxml')->everyMinute());
$lock(Schedule::command('sefaz:dispatch-ma-outbound-due')->everyMinute());
$lock(Schedule::command('sefaz:dispatch-svrs-nfce-xml-recoveries')->everyMinute());
$lock(Schedule::command('fiscal:dispatch-due-monitoring')->everyMinute());
$lock(Schedule::command('fgts-digital:dispatch-due')->everyMinute());
$lock(Schedule::command('mailbox:dispatch-due-monitoring')->everyMinute());
$lock(Schedule::command('communication:dispatch-outbox')->everyMinute());
$lock(Schedule::command('communication:dispatch-fiscal')->everyMinute());
$lock(Schedule::command('communication:wake-snoozed')->everyMinute());
$lock(Schedule::command('communication:reconcile-flow-runs')->everyMinute());
$lock(Schedule::command('work:dispatch-due-recurrence')->everyMinute());

$lock(Schedule::command('outbound:deadline-plan')->hourly(), 55);
$lock(Schedule::command('exports:purge-expired')->hourly(), 55);
$lock(Schedule::command('import:purge-expired-spools')->hourly(), 55);
$lock(Schedule::command('credentials:refresh-expiry')->hourly(), 55);
// SERPRO lifecycle: alertas de PFX/certificado/Termo/token/procurações — sem assinar/mutar
$lock(Schedule::command('serpro:lifecycle-scan')->dailyAt('04:00'), 120);
// Consulta oficial de procurações: no-op até flag + allowlist + capability explícitas.
$lock(Schedule::command('serpro:dispatch-due-procuracao-syncs')->hourly(), 55);
// SERPRO ops: breaker, fila parada, budget, drift, runbooks + Horizon snapshot
$lock(Schedule::command('serpro:ops-scan --horizon-snapshot')->everyFiveMinutes(), 4);
if (config('serpro.observability.horizon_snapshot_enabled', true)) {
    $lock(Schedule::command('horizon:snapshot')->everyFiveMinutes(), 4);
}
// Integridade da cadeia de auditoria (alerta sem PII)
$lock(Schedule::command('audit:verify-chain --alert')->dailyAt('03:30'), 120);
// GC seguro pós-offboarding (após prazo legal)
$lock(Schedule::command('serpro:retention-gc')->dailyAt('04:15'), 120);

if (config('backup.schedule_enabled')) {
    $lock(Schedule::command('ops:backup-run --kind=full')->dailyAt('02:15'), 180);
}
