<?php

namespace App\Console\Commands;

use App\Services\Ops\ProductionReadinessService;
use Illuminate\Console\Command;

/**
 * Readiness global de produção — sem Office, sem transporte fiscal externo.
 */
class OpsProductionReadinessCommand extends Command
{
    protected $signature = 'ops:production-readiness
        {--json : Saída JSON sanitizada (allowlist)}
        {--no-persist : Não persiste evidência (sempre true neste comando; flag de contrato)}';

    protected $description = 'Avalia readiness de produção (ambiente, DB, filas, scheduler, contenção fiscal)';

    public function handle(ProductionReadinessService $readiness): int
    {
        // --no-persist é always-on: não grava evidência no banco;
        // o host orquestra JSON fora do repositório.

        $snapshot = $readiness->evaluate();

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('ops:production-readiness ok='.($snapshot['ok'] ? 'true' : 'false'));
            foreach ($snapshot['checks'] as $check) {
                $mark = $check['ok'] ? 'OK' : 'FAIL';
                $this->line(sprintf('  [%s] %s — %s', $mark, $check['id'], $check['detail']));
            }
            if ($snapshot['issues'] !== []) {
                $this->error('Issues: '.count($snapshot['issues']));
            }
        }

        return $snapshot['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
