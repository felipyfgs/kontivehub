<?php

namespace App\Console\Commands;

use App\Services\Platform\MultitenantRbacMigrateService;
use Illuminate\Console\Command;

/**
 * Preflight/backfill do RBAC multi-tenant canônico.
 *
 * @example php artisan app:multitenant-rbac:migrate --dry-run
 * @example php artisan app:multitenant-rbac:migrate --apply --primary-office=1 --confirm
 */
class MultitenantRbacMigrateCommand extends Command
{
    protected $signature = 'app:multitenant-rbac:migrate
        {--dry-run : Inventário e paridade sem gravar (default se --apply ausente)}
        {--apply : Executa backfill idempotente}
        {--primary-office= : ID do tenant principal (obrigatório no apply; confirmação no dry-run)}
        {--confirm : Confirma writes no apply}';

    protected $description = 'Migra papéis legados para RBAC multi-tenant canônico (dry-run/apply)';

    public function handle(MultitenantRbacMigrateService $service): int
    {
        $apply = (bool) $this->option('apply');
        $primary = $this->option('primary-office');
        $primaryId = $primary !== null && $primary !== '' ? (int) $primary : null;
        $confirm = (bool) $this->option('confirm');

        if ($apply && $this->option('dry-run')) {
            $this->warn('Ignorando --dry-run porque --apply foi informado.');
        }

        $report = $service->run(
            apply: $apply,
            primaryOfficeId: $primaryId,
            confirm: $confirm,
        );

        $this->line('mode: '.$report['mode']);
        $this->line('primary_office_id: '.($report['primary_office_id'] ?? 'null'));
        $this->line('applied: '.($report['applied'] ? 'yes' : 'no'));
        $this->line('sessions_revoked: '.$report['sessions_revoked']);
        $this->newLine();
        $this->info('Contagens sanitizadas:');
        $this->line(json_encode($report['counts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->newLine();
        $this->info('Offices:');
        $this->line(json_encode($report['offices'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($report['blockers'] !== []) {
            $this->newLine();
            $this->error('Blockers:');
            foreach ($report['blockers'] as $blocker) {
                $this->line(' - '.$blocker);
            }
        }

        if ($report['blocked'] && $apply) {
            return self::FAILURE;
        }

        // dry-run com blockers ainda retorna SUCCESS para inspeção, mas exit 1 se
        // o operador pediu apply e não conseguiu.
        if ($report['blocked'] && ! $apply) {
            $this->warn('Dry-run concluído com pendências (não grava).');
        }

        return self::SUCCESS;
    }
}
