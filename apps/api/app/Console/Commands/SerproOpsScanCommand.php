<?php

namespace App\Console\Commands;

use App\Services\Serpro\SerproOpsAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Scan operacional SERPRO: lifecycle, breaker, filas, budgets, drift + snapshot métricas.
 * Opcionalmente dispara horizon:snapshot (métricas de fila).
 */
class SerproOpsScanCommand extends Command
{
    protected $signature = 'serpro:ops-scan
        {--json : Saída JSON sanitizada}
        {--horizon-snapshot : Também executa horizon:snapshot}';

    protected $description = 'Alertas/runbooks SERPRO + métricas (sem PII, sem egress fiscal)';

    public function handle(SerproOpsAlertService $ops): int
    {
        if (! (bool) config('serpro.observability.ops_scan_enabled', true)) {
            $this->warn('serpro.observability.ops_scan_enabled=false — scan ignorado.');

            return self::SUCCESS;
        }

        $result = $ops->scan();

        if ($this->option('horizon-snapshot')
            || (bool) config('serpro.observability.horizon_snapshot_enabled', true)
        ) {
            try {
                Artisan::call('horizon:snapshot');
                $result['horizon_snapshot'] = 'ok';
            } catch (\Throwable $e) {
                $result['horizon_snapshot'] = 'skipped';
                $this->warn('horizon:snapshot indisponível: '.mb_substr($e->getMessage(), 0, 120));
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info(sprintf(
                'Ops scan: alerts=%d lifecycle_lock=%s breaker=%s',
                count($result['alerts'] ?? []),
                ($result['lifecycle']['lock_acquired'] ?? false) ? 'yes' : 'no',
                $result['metrics']['breaker']['state'] ?? '?',
            ));
            foreach ($result['alerts'] as $alert) {
                $this->line(sprintf(
                    '  [%s] %s runbook=%s',
                    $alert['severity'] ?? '-',
                    $alert['kind'] ?? '?',
                    $alert['runbook'] ?? '—',
                ));
            }
        }

        return self::SUCCESS;
    }
}
