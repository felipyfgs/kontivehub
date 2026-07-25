<?php

namespace App\Console\Commands;

use App\Services\Sefaz\SefazSyncDispatchService;
use Illuminate\Console\Command;

class DispatchDueSefazSyncsCommand extends Command
{
    protected $signature = 'sefaz:dispatch-due-syncs {--limit=50 : Máximo de cursores por tick}';

    protected $description = 'Enfileira capturas DistDFe SEFAZ (NF-e / CT-e) de cursores vencidos';

    public function handle(SefazSyncDispatchService $dispatcher): int
    {
        if (! config('sefaz.distdfe_enabled') && ! config('sefaz.cte_enabled')) {
            $this->line('SEFAZ_DISTDFE_ENABLED e SEFAZ_CTE_ENABLED off — nada a enfileirar.');

            return self::SUCCESS;
        }

        $n = $dispatcher->dispatchDue((int) $this->option('limit'));
        $this->info("SEFAZ DistDFe (NFE/CTE): {$n} job(s) enfileirado(s).");

        return self::SUCCESS;
    }
}
