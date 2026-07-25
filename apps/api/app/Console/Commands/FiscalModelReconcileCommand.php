<?php

namespace App\Console\Commands;

use App\Services\FiscalDataModel\FiscalModelReconcileService;
use App\Support\FiscalDataModel\FiscalModelAggregates;
use Illuminate\Console\Command;

class FiscalModelReconcileCommand extends Command
{
    protected $signature = 'fiscal-model:reconcile
                            {aggregate? : Agregado opcional; omite = todos}
                            {--json : Saída JSON sanitizada}';

    protected $description = 'Reconcilia invariantes do modelo fiscal; exit 1 se divergência não aprovada';

    public function handle(FiscalModelReconcileService $service): int
    {
        $aggregate = $this->argument('aggregate');
        $list = null;
        if ($aggregate !== null && $aggregate !== '') {
            if (! FiscalModelAggregates::isKnown((string) $aggregate)) {
                $this->error('Agregado desconhecido. Use: '.implode(', ', FiscalModelAggregates::all()));

                return self::FAILURE;
            }
            $list = [(string) $aggregate];
        }

        $report = $service->run($list);

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($report->passed ? 'RECONCILE_OK' : 'RECONCILE_FAIL');
            $this->line('matches='.count($report->matches).' divergences='.count($report->divergences));
            foreach ($report->divergences as $d) {
                $this->warn(sprintf(
                    '[%s] %s expected=%s actual=%s severity=%s',
                    $d['aggregate'],
                    $d['metric'],
                    json_encode($d['expected']),
                    json_encode($d['actual']),
                    $d['severity'] ?? 'error',
                ));
            }
        }

        return $report->passed ? self::SUCCESS : self::FAILURE;
    }
}
