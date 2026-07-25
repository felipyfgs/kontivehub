<?php

namespace App\Console\Commands;

use App\Services\Ops\ProductionReadinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Heartbeat leve do scheduler — sem integração externa.
 */
class OpsSchedulerHeartbeatCommand extends Command
{
    protected $signature = 'ops:scheduler-heartbeat';

    protected $description = 'Registra heartbeat do scheduler para o gate de readiness';

    public function handle(): int
    {
        $key = (string) config(
            'ops.scheduler_heartbeat.cache_key',
            ProductionReadinessService::HEARTBEAT_CACHE_KEY
        );

        $stamp = now()->utc()->toIso8601String();
        Cache::put($key, $stamp, now()->addDay());

        if ($this->output->isVerbose()) {
            $this->line('scheduler heartbeat='.$stamp);
        }

        return self::SUCCESS;
    }
}
