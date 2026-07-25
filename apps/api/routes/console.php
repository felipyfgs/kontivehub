<?php

use Illuminate\Support\Facades\Schedule;

// Heartbeat leve do scheduler (readiness de produção; sem integração externa).
Schedule::command('ops:scheduler-heartbeat')->everyMinute();

Schedule::command('adn:dispatch-due-syncs')->everyMinute();
Schedule::command('sefaz:dispatch-due-syncs')->everyMinute();
Schedule::command('sefaz:dispatch-due-autxml')->everyMinute();
Schedule::command('sefaz:dispatch-due-cte-autxml')->everyMinute();
Schedule::command('sefaz:dispatch-ma-outbound-due')->everyMinute();
Schedule::command('sefaz:dispatch-svrs-nfce-xml-recoveries')->everyMinute();
Schedule::command('fiscal:dispatch-due-monitoring')->everyMinute();
Schedule::command('fgts-digital:dispatch-due')->everyMinute();
Schedule::command('mailbox:dispatch-due-monitoring')->everyMinute();
Schedule::command('communication:dispatch-outbox')->everyMinute();
Schedule::command('communication:dispatch-fiscal')->everyMinute();
Schedule::command('communication:wake-snoozed')->everyMinute();
Schedule::command('communication:reconcile-flow-runs')->everyMinute();
Schedule::command('work:dispatch-due-recurrence')->everyMinute();
Schedule::command('outbound:deadline-plan')->hourly();
Schedule::command('exports:purge-expired')->hourly();
Schedule::command('import:purge-expired-spools')->hourly();
Schedule::command('credentials:refresh-expiry')->hourly();
// SERPRO lifecycle: alertas de PFX/A1/Termo/token/procurações — sem assinar/mutar
Schedule::command('serpro:lifecycle-scan')->dailyAt('04:00');
// Consulta oficial de procurações: no-op até flag + allowlist + capability explícitas.
Schedule::command('serpro:dispatch-due-procuracao-syncs')->hourly();
// SERPRO ops: breaker, fila parada, budget, drift, runbooks + Horizon snapshot
Schedule::command('serpro:ops-scan --horizon-snapshot')->everyFiveMinutes();
if (config('serpro.observability.horizon_snapshot_enabled', true)) {
    Schedule::command('horizon:snapshot')->everyFiveMinutes();
}
// Integridade da cadeia de auditoria (alerta sem PII)
Schedule::command('audit:verify-chain --alert')->dailyAt('03:30');
// GC seguro pós-offboarding (após prazo legal)
Schedule::command('serpro:retention-gc')->dailyAt('04:15');

if (config('backup.schedule_enabled')) {
    Schedule::command('ops:backup-run --kind=full')->dailyAt('02:15');
}
