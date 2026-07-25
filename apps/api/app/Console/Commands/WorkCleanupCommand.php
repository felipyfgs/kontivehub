<?php

namespace App\Console\Commands;

use App\Services\Work\OperationalVaultCleanupService;
use Illuminate\Console\Command;

class WorkCleanupCommand extends Command
{
    protected $signature = 'work:cleanup';

    protected $description = 'Limpa previews expirados, exports CSV operacionais e evidências removidas (somente purpose operacional).';

    public function handle(OperationalVaultCleanupService $cleanup): int
    {
        $result = $cleanup->run();
        $this->info(sprintf(
            'previews=%d exports=%d evidences=%d',
            $result['previews'],
            $result['exports'],
            $result['evidences'],
        ));

        return self::SUCCESS;
    }
}
