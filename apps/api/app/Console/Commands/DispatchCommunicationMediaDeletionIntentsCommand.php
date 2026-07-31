<?php

namespace App\Console\Commands;

use App\Services\Communication\Media\MediaDeletionService;
use App\Services\Communication\Media\MediaStore;
use Illuminate\Console\Command;

final class DispatchCommunicationMediaDeletionIntentsCommand extends Command
{
    protected $signature = 'communication:dispatch-media-deletions {--limit=100} {--sweep} {--grace=1440}';

    public function handle(MediaDeletionService $service): int
    {
        $limit = (int) $this->option('limit');
        if ($this->option('sweep')) {
            $service->sweepOrphans(app(MediaStore::class), $limit, (int) $this->option('grace'));
        }
        $this->info((string) $service->dispatchDue($limit));

        return self::SUCCESS;
    }
}
