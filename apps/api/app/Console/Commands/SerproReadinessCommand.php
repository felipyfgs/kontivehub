<?php

namespace App\Console\Commands;

use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Tenant;
use App\Services\Serpro\SerproReadinessService;
use Illuminate\Console\Command;

/**
 * Readiness SERPRO read-only: não emite token nem chama serviço fiscal.
 */
class SerproReadinessCommand extends Command
{
    protected $signature = 'serpro:readiness
        {--serpro-env= : Ambiente SERPRO}
        {--tenant= : ID do Tenant (avaliação tenant)}
        {--client= : ID do Cliente (com --tenant e opcional --operation)}
        {--operation= : operation_key (com --tenant)}
        {--no-persist : Não grava serpro_readiness_runs}
        {--json : Saída JSON sanitizada}';

    protected $description = 'Avalia gates de readiness SERPRO (offline, sem egress fiscal)';

    public function handle(SerproReadinessService $readiness): int
    {
        $envRaw = $this->option('serpro-env')
            ?: (string) config('serpro.default_environment', 'TRIAL');
        $environment = SerproEnvironment::tryFrom(strtoupper((string) $envRaw))
            ?? SerproEnvironment::Trial;

        $persist = ! $this->option('no-persist');

        if ($this->option('tenant')) {
            $tenant = Tenant::query()->find((int) $this->option('tenant'));
            if ($tenant === null) {
                $this->error('Tenant não encontrado.');

                return self::FAILURE;
            }

            $client = null;
            if ($this->option('client')) {
                $client = Client::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->whereKey((int) $this->option('client'))
                    ->first();
                if ($client === null) {
                    $this->error('Cliente não encontrado no tenant.');

                    return self::FAILURE;
                }
            }

            if ($this->option('operation')) {
                $run = $readiness->evaluateOperation(
                    $tenant,
                    (string) $this->option('operation'),
                    $client,
                    $environment,
                    persist: $persist,
                );
            } else {
                $run = $readiness->evaluateTenant($tenant, $environment, persist: $persist);
            }
            $payload = is_array($run) ? $run : $run->toSanitizedArray();
        } else {
            $run = $readiness->evaluateGlobal($environment, persist: $persist);
            $payload = is_array($run) ? $run : $run->toSanitizedArray();
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Readiness env='.$environment->value.' result='.($payload['result'] ?? '?'));
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $result = is_array($payload) ? ($payload['result'] ?? null) : null;
        if ($result === 'FAIL') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
