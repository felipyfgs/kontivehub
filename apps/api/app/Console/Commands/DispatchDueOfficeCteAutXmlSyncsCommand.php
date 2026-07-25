<?php

namespace App\Console\Commands;

use App\Enums\CaptureChannel;
use App\Enums\SyncCursorStatus;
use App\Jobs\SyncOfficeCteAutXmlDistDfeJob;
use App\Models\OfficeDistributionCursor;
use App\Support\CteAutXmlFeature;
use Illuminate\Console\Command;

class DispatchDueOfficeCteAutXmlSyncsCommand extends Command
{
    protected $signature = 'sefaz:dispatch-due-cte-autxml {--limit=20 : Máximo de cursores por tick}';

    protected $description = 'Enfileira capturas CTeDistribuicaoDFe autXML do escritório (cursores vencidos)';

    public function handle(): int
    {
        if (! CteAutXmlFeature::isGloballyEnabled()) {
            $this->line('SEFAZ_CTE_AUTXML_DISTDFE_ENABLED off — nada a enfileirar.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $due = OfficeDistributionCursor::query()
            ->where('channel', CaptureChannel::CteAutXmlDistDfe)
            ->whereIn('status', [SyncCursorStatus::Idle, SyncCursorStatus::Error ?? SyncCursorStatus::Idle])
            ->where(function ($q) {
                $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('external_consumer_status')
                    ->orWhere('external_consumer_status', '!=', 'EXTERNAL_CONSUMER_CONFLICT');
            })
            ->orderBy('next_sync_at')
            ->limit($limit)
            ->get();

        $n = 0;
        foreach ($due as $cursor) {
            if (! CteAutXmlFeature::isOfficeAllowed((int) $cursor->office_id)) {
                continue;
            }
            $delay = ($cursor->id % 60);
            SyncOfficeCteAutXmlDistDfeJob::dispatch($cursor->id, 'SCHEDULED')
                ->delay(now()->addSeconds($delay));
            $n++;
        }

        $this->info("SEFAZ CT-e autXML: {$n} job(s) enfileirado(s).");

        return self::SUCCESS;
    }
}
