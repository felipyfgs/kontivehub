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
    public const HEALTHCHECK_FILE = '/tmp/kontivehub-scheduler-heartbeat';

    protected $signature = 'ops:scheduler-heartbeat';

    protected $description = 'Registra heartbeat do scheduler para o gate de readiness';

    public function handle(): int
    {
        $key = (string) config(
            'ops.scheduler_heartbeat.cache_key',
            ProductionReadinessService::HEARTBEAT_CACHE_KEY
        );

        $stamp = now()->utc()->toIso8601String();
        $payload = $stamp."\n";
        $temporaryFile = self::HEALTHCHECK_FILE.'.tmp.'.getmypid();
        $written = @file_put_contents($temporaryFile, $payload, LOCK_EX);

        if ($written !== strlen($payload) || ! @rename($temporaryFile, self::HEALTHCHECK_FILE)) {
            @unlink($temporaryFile);
            $this->error('Não foi possível atualizar o heartbeat local do scheduler.');

            return self::FAILURE;
        }

        Cache::put($key, $stamp, now()->addDay());

        if ($this->output->isVerbose()) {
            $this->line('scheduler heartbeat='.$stamp);
        }

        return self::SUCCESS;
    }
}
