<?php

namespace App\Console\Commands;

use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPagtowebReconciliationService;
use Illuminate\Console\Command;

final class BackfillPgdasdPagtowebPaymentsCommand extends Command
{
    protected $signature = 'fiscal:backfill-pgdasd-pagtoweb
        {--office= : Limita o backfill a um escritório}
        {--clients= : Máximo de clientes neste ciclo}
        {--documents= : Orçamento máximo de DAS neste ciclo}';

    protected $description = 'Enfileira backfill limitado da evidência de pagamento PGDAS-D via PAGTOWEB';

    public function handle(PgdasdPagtowebReconciliationService $reconciliation): int
    {
        $officeId = $this->option('office') !== null ? max(1, (int) $this->option('office')) : null;
        $clients = $this->option('clients') !== null ? max(1, (int) $this->option('clients')) : null;
        $documents = $this->option('documents') !== null ? max(1, (int) $this->option('documents')) : null;

        $result = $reconciliation->backfill($officeId, $clients, $documents);
        $this->info(sprintf(
            'PGDASD/PAGTOWEB backfill: clients=%d queued=%d documents=%d',
            $result['clients'],
            $result['queued'],
            $result['documents'],
        ));

        return self::SUCCESS;
    }
}
