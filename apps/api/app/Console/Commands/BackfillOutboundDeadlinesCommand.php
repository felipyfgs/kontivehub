<?php

namespace App\Console\Commands;

use App\Enums\OutboundRetrievalOrigin;
use App\Models\MaOutboundRetrievalRequest;
use App\Services\Outbound\OutboundDeadlinePlannerService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Backfill idempotente de prazos — sem acesso externo / sem PFX.
 */
class BackfillOutboundDeadlinesCommand extends Command
{
    protected $signature = 'outbound:deadline-backfill
        {--office= : office_id opcional}
        {--limit=500 : lote por execução}
        {--from-id=0 : continua a partir do id}';

    protected $description = 'Recalcula due_at/target_at/faixas em recoveries existentes (sem rede)';

    public function handle(OutboundDeadlinePlannerService $planner): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $fromId = max(0, (int) $this->option('from-id'));
        $office = $this->option('office') !== null ? (int) $this->option('office') : null;
        $now = CarbonImmutable::now('UTC');

        $q = MaOutboundRetrievalRequest::query()
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->where('id', '>', $fromId)
            ->orderBy('id')
            ->limit($limit);

        if ($office !== null) {
            $q->where('office_id', $office);
        }

        $n = 0;
        $lastId = $fromId;
        foreach ($q->get() as $row) {
            $planner->refreshDeadlineFields($row, $now);
            $n++;
            $lastId = (int) $row->id;
        }

        $this->info("Backfill: {$n} recovery(ies). last_id={$lastId}");

        return self::SUCCESS;
    }
}
