<?php

namespace App\Console\Commands;

use App\Services\Sefaz\CteReconciliationService;
use Illuminate\Console\Command;

/** Reconcilia em lote eventos órfãos e quarentenas associáveis (escopo tenant). */
class CteReconcileOrphansCommand extends Command
{
    protected $signature = 'cte:reconcile-orphans
                            {--tenant= : tenant_id obrigatório em multi-tenant}
                            {--limit=200 : Máximo de chaves a processar}';

    protected $description = 'Reconcilia eventos órfãos e pendências CT-e associáveis por tenant';

    public function handle(CteReconciliationService $service): int
    {
        $tenantOpt = $this->option('tenant');
        if ($tenantOpt === null || $tenantOpt === '') {
            $this->error('--tenant= é obrigatório (isolamento multi-escritório).');

            return self::FAILURE;
        }

        $tenantId = (int) $tenantOpt;
        $limit = max(1, min(2000, (int) $this->option('limit')));

        $result = $service->reconcileOrphans($tenantId, $limit);

        $this->info(sprintf(
            'tenant=%d keys=%d events_linked=%d quarantines_resolved=%d coverage_recomputed=%d',
            $tenantId,
            $result['keys_processed'],
            $result['events_linked'],
            $result['quarantines_resolved'],
            $result['coverage_recomputed'],
        ));

        return self::SUCCESS;
    }
}
