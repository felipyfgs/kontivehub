<?php

namespace App\Console\Commands;

use App\Services\Sefaz\CteReconciliationService;
use Illuminate\Console\Command;

/** Reconcilia em lote eventos órfãos e quarentenas associáveis (escopo office). */
class CteReconcileOrphansCommand extends Command
{
    protected $signature = 'cte:reconcile-orphans
                            {--office= : office_id obrigatório em multi-tenant}
                            {--limit=200 : Máximo de chaves a processar}';

    protected $description = 'Reconcilia eventos órfãos e pendências CT-e associáveis por office';

    public function handle(CteReconciliationService $service): int
    {
        $officeOpt = $this->option('office');
        if ($officeOpt === null || $officeOpt === '') {
            $this->error('--office= é obrigatório (isolamento multi-escritório).');

            return self::FAILURE;
        }

        $officeId = (int) $officeOpt;
        $limit = max(1, min(2000, (int) $this->option('limit')));

        $result = $service->reconcileOrphans($officeId, $limit);

        $this->info(sprintf(
            'office=%d keys=%d events_linked=%d quarantines_resolved=%d coverage_recomputed=%d',
            $officeId,
            $result['keys_processed'],
            $result['events_linked'],
            $result['quarantines_resolved'],
            $result['coverage_recomputed'],
        ));

        return self::SUCCESS;
    }
}
