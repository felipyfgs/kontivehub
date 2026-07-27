<?php

namespace App\Console\Commands;

use App\Services\Sefaz\AutoCienciaScheduler;
use Illuminate\Console\Command;

/**
 * Enfileira ciência técnica para resumos PENDING sem procNFe (catch-up / reprocesso).
 */
class EnqueueAutoCienciaCommand extends Command
{
    protected $signature = 'sefaz:enqueue-auto-ciencia
        {--tenant= : Filtrar por tenant_id}
        {--establishment= : Filtrar por establishment_id}
        {--limit=100 : Máximo de chaves a enfileirar}';

    protected $description = 'Enfileira ciência automática (210210) para resNFe pendentes sem XML completo';

    public function handle(AutoCienciaScheduler $scheduler): int
    {
        if (! $scheduler->isEnabled()) {
            $this->warn('SEFAZ_AUTO_CIENCIA_ENABLED e SEFAZ_MANIFEST_ENABLED estão off — nada a enfileirar.');

            return self::SUCCESS;
        }

        $tenant = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $establishment = $this->option('establishment') !== null ? (int) $this->option('establishment') : null;
        $limit = max(1, (int) $this->option('limit'));

        $n = $scheduler->enqueuePending($tenant, $establishment, $limit);
        $this->info("Ciências enfileiradas: {$n}");

        return self::SUCCESS;
    }
}
