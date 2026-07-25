<?php

namespace App\Console\Commands;

use App\Services\Serpro\SerproOffboardingService;
use Illuminate\Console\Command;

class SerproRetentionGcCommand extends Command
{
    protected $signature = 'serpro:retention-gc
        {--limit=50 : Máximo de jobs por execução}
        {--json : Saída JSON}';

    protected $description = 'GC seguro de material SERPRO após prazo legal (pós-offboarding)';

    public function handle(SerproOffboardingService $offboarding): int
    {
        $result = $offboarding->runSafeGc((int) $this->option('limit'));

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));
        } else {
            $this->info(sprintf(
                'RETENTION_GC purged=%d skipped=%d',
                $result['purged'],
                $result['skipped'],
            ));
        }

        return self::SUCCESS;
    }
}
