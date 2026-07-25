<?php

namespace App\Console\Commands;

use App\Services\FiscalDataModel\SerproPeriodReconcileService;
use Illuminate\Console\Command;

class FiscalModelSerproReconcileCommand extends Command
{
    protected $signature = 'fiscal-model:reconcile-serpro
                            {--period= : YYYY-MM}
                            {--json : Saída JSON}';

    protected $description = 'Reconcilia ledger SERPRO vs agregados do período';

    public function handle(SerproPeriodReconcileService $service): int
    {
        $period = $this->option('period') ?: null;
        $report = $service->run($period ? (string) $period : null);

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($report->passed ? 'SERPRO_RECONCILE_OK' : 'SERPRO_RECONCILE_FAIL');
        }

        return $report->passed ? self::SUCCESS : self::FAILURE;
    }
}
