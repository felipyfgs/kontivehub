<?php

namespace App\Console\Commands;

use App\Services\FiscalDataModel\FiscalModelReconcileService;
use App\Support\FiscalDataModel\FiscalModelAggregates;
use App\Support\FiscalDataModel\FiscalModelCutover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Shadow verification: compara invariantes sem payload sensível.
 * Não ativa corte de leitura — apenas observa e reporta.
 */
class FiscalModelShadowVerifyCommand extends Command
{
    protected $signature = 'fiscal-model:shadow-verify
                            {aggregate? : Agregado opcional}
                            {--json : Saída JSON}';

    protected $description = 'Shadow verification por agregado (sanitizado, sem segredos)';

    public function handle(FiscalModelReconcileService $reconcile): int
    {
        $aggregate = $this->argument('aggregate');
        $list = null;
        if ($aggregate !== null && $aggregate !== '') {
            if (! FiscalModelAggregates::isKnown((string) $aggregate)) {
                $this->error('Agregado desconhecido');

                return self::FAILURE;
            }
            $list = [(string) $aggregate];
        } else {
            $list = FiscalModelAggregates::all();
        }

        $report = $reconcile->run($list);
        $payload = [
            'shadow' => true,
            'kill_switch' => FiscalModelCutover::isKillSwitchActive(),
            'read_authorities' => [],
            'report' => $report->toArray(),
        ];
        foreach ($list as $agg) {
            $payload['read_authorities'][$agg] = FiscalModelCutover::readAuthority($agg);
            $payload['shadow_flag'][$agg] = FiscalModelCutover::shadowVerify($agg);
        }

        Log::info('fiscal_model.shadow_verify', [
            'passed' => $report->passed,
            'divergences_count' => count($report->divergences),
            'aggregates' => $list,
        ]);

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($report->passed ? 'SHADOW_OK' : 'SHADOW_DIVERGENCE');
            foreach ($report->divergences as $d) {
                $this->warn(sprintf(
                    '[%s] %s expected=%s actual=%s',
                    $d['aggregate'],
                    $d['metric'],
                    json_encode($d['expected']),
                    json_encode($d['actual']),
                ));
            }
        }

        return $report->passed ? self::SUCCESS : self::FAILURE;
    }
}
