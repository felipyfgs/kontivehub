<?php

namespace App\Console\Commands;

use App\Jobs\SyncTenantAutXmlDistDfeJob;
use App\Services\Sefaz\TenantDistributionCursorService;
use App\Support\AutXmlFeature;
use Illuminate\Console\Command;

class DispatchDueTenantAutXmlSyncsCommand extends Command
{
    protected $signature = 'sefaz:dispatch-due-autxml {--limit=20 : Máximo de cursores por tick}';

    protected $description = 'Enfileira capturas DistDFe autXML do escritório (cursores vencidos)';

    public function handle(TenantDistributionCursorService $cursors): int
    {
        if (! AutXmlFeature::isGloballyEnabled()) {
            $this->line('SEFAZ_AUTXML_DISTDFE_ENABLED off — nada a enfileirar.');

            return self::SUCCESS;
        }

        $due = $cursors->dueCursors((int) $this->option('limit'));
        $n = 0;
        foreach ($due as $cursor) {
            // Spread determinístico leve pelo id do cursor (evita thundering herd)
            $delay = ($cursor->id % 60);
            SyncTenantAutXmlDistDfeJob::dispatch($cursor->id, 'SCHEDULED')
                ->delay(now()->addSeconds($delay));
            $n++;
        }

        $this->info("SEFAZ autXML: {$n} job(s) enfileirado(s).");

        return self::SUCCESS;
    }
}
