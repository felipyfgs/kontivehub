<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Destino dos artefatos de backup da instância
    |--------------------------------------------------------------------------
    |
    | Fora do webroot. Paths relativos no manifesto são relativos a este root.
    |
    */
    'disk_root' => env('BACKUP_DISK_ROOT', storage_path('app/backups')),

    /*
    |--------------------------------------------------------------------------
    | Retenção
    |--------------------------------------------------------------------------
    |
    | Quantidade de diretórios de run (kind full/database/vault SUCCESS/FAILED)
    | a manter no disco. Runs de restore_drill não contam como artefato de backup.
    |
    */
    // Quantidade de artefatos SUCCESS (full/database/vault) a manter no disco.
    'retention_runs' => (int) env('BACKUP_RETENTION_RUNS', 7),

    // Quantidade de artefatos FAILED recentes a manter para diagnóstico.
    'retention_failed_runs' => (int) env('BACKUP_RETENTION_FAILED_RUNS', 2),

    /*
    |--------------------------------------------------------------------------
    | Agendamento
    |--------------------------------------------------------------------------
    |
    | Default false em dev: backup diário só se explicitamente habilitado.
    |
    */
    'schedule_enabled' => (bool) env('BACKUP_SCHEDULE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Lock anti-concorrência
    |--------------------------------------------------------------------------
    */
    'lock_name' => 'ops.backup-run',
    'lock_seconds' => (int) env('BACKUP_LOCK_SECONDS', 3600),

    /*
    |--------------------------------------------------------------------------
    | Alerta de atraso
    |--------------------------------------------------------------------------
    */
    'stale_after_hours' => (int) env('BACKUP_STALE_AFTER_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Chave externa do pacote unificado (cifra+autentica artefato)
    |--------------------------------------------------------------------------
    |
    | base64 de 32 bytes. NÃO é VAULT_MASTER_KEY. Ausente = pacote em claro
    | com checksums (legado v2). Preferir sempre definida em produção.
    |
    */
    'package_key' => env('BACKUP_PACKAGE_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Restore drill: validar referências DB→vault e prova de decrypt
    |--------------------------------------------------------------------------
    */
    'drill_validate_vault_refs' => filter_var(
        env('BACKUP_DRILL_VALIDATE_VAULT_REFS', true),
        FILTER_VALIDATE_BOOL
    ),
    'drill_sample_decrypt' => filter_var(
        env('BACKUP_DRILL_SAMPLE_DECRYPT', true),
        FILTER_VALIDATE_BOOL
    ),
];
