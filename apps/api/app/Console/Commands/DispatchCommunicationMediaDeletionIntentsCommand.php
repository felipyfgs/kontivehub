<?php

namespace App\Console\Commands;

use App\Services\Communication\Media\CommunicationMediaDeletionService;
use App\Services\Communication\Media\CommunicationMediaStore;
use Illuminate\Console\Command;

final class DispatchCommunicationMediaDeletionIntentsCommand extends Command
{
    protected $signature = 'communication:dispatch-media-deletions {--limit=100} {--sweep} {--grace=1440}';

    public function handle(CommunicationMediaDeletionService $service): int
    {
        $limit = (int) $this->option('limit');
        if ($this->option('sweep')) {
            $service->sweepOrphans(app(CommunicationMediaStore::class), $limit, (int) $this->option('grace'));
        }
        $this->info((string) $service->dispatchDue($limit));

        return self::SUCCESS;
    }
}
