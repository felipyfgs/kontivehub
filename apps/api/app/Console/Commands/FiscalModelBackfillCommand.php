<?php

namespace App\Console\Commands;

use App\Services\FiscalDataModel\FiscalModelBackfillService;
use App\Support\FiscalDataModel\FiscalModelAggregates;
use Illuminate\Console\Command;
use Throwable;

class FiscalModelBackfillCommand extends Command
{
    protected $signature = 'fiscal-model:backfill
                            {aggregate : Agregado (tenancy_cadastro|documentos_cursores|outbound|serpro|monitoramento_guias)}
                            {--dry-run : Não grava mapas/checkpoints}
                            {--office= : Restringe a um office_id}
                            {--json : Saída JSON sanitizada}';

    protected $description = 'Backfill idempotente do modelo fiscal consolidado (checkpoint + mapa origem-destino)';

    public function handle(FiscalModelBackfillService $service): int
    {
        $aggregate = (string) $this->argument('aggregate');
        if (! FiscalModelAggregates::isKnown($aggregate)) {
            $this->error('Agregado desconhecido. Use: '.implode(', ', FiscalModelAggregates::all()));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $office = $this->option('office');
        $officeId = ($office !== null && $office !== '') ? (int) $office : null;

        try {
            $result = $service->run($aggregate, $dryRun, $officeId);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $prefix = $dryRun ? '[dry-run] ' : '';
            $this->info($prefix."aggregate={$result->aggregate} processed={$result->processed} mapped={$result->mapped} skipped={$result->skipped} rejected={$result->rejected} ambiguous={$result->ambiguous}");
            if ($result->checkpoint !== null) {
                $this->line("checkpoint={$result->checkpoint}");
            }
        }

        return ($result->rejected > 0 || $result->ambiguous > 0) ? self::FAILURE : self::SUCCESS;
    }
}
