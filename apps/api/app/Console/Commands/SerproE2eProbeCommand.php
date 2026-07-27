<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Tenant;
use App\Services\Serpro\E2e\SerproE2eProbeService;
use Illuminate\Console\Command;

/**
 * Probe e2e PRODUCTION via executor real (opt-in).
 *
 * Uso piloto:
 *   php artisan serpro:e2e-probe --tenant=2 --client=1 --all --artifact-dir=/path
 *   php artisan serpro:e2e-probe --tenant=2 --client=1 --only=sitfis.solicitar_protocolo,pgdasd.consdeclaracao
 */
final class SerproE2eProbeCommand extends Command
{
    protected $signature = 'serpro:e2e-probe
        {--tenant= : Tenant ID (piloto)}
        {--client= : Client ID (contribuinte)}
        {--all : Todas as ops PRODUCTION}
        {--only= : Lista CSV de operation_key}
        {--artifact-dir= : Diretório para JSON sanitizados}
        {--json : Imprime summary JSON}';

    protected $description = 'Probe e2e SERPRO Integra Contador (PRODUCTION) via SerproOperationService real';

    public function handle(SerproE2eProbeService $probe): int
    {
        $tenantId = (int) $this->option('tenant');
        $clientId = (int) $this->option('client');
        if ($tenantId <= 0 || $clientId <= 0) {
            $this->error('--tenant e --client são obrigatórios.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->find($tenantId);
        $client = Client::query()->withoutGlobalScopes()->find($clientId);
        if ($tenant === null || $client === null) {
            $this->error('Tenant/client não encontrados.');

            return self::FAILURE;
        }
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            $this->error('Cliente não pertence ao tenant.');

            return self::FAILURE;
        }

        $artifactDir = (string) ($this->option('artifact-dir') ?: storage_path('app/serpro-e2e-probe'));
        $only = null;
        if ($this->option('only')) {
            $only = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))));
        } elseif (! $this->option('all')) {
            $this->error('Informe --all ou --only=op1,op2');

            return self::FAILURE;
        }

        $this->info("Probe e2e tenant={$tenant->id} client={$client->id} dir={$artifactDir}");
        $batch = $probe->probeAllProduction($tenant, $client, $artifactDir, $only);

        if ($this->option('json')) {
            $this->line(json_encode([
                'summary' => $batch['summary'],
                'tracker_path' => $batch['tracker_path'],
                'sitfis_protocol_present' => $batch['sitfis_protocol'] !== null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['metric', 'value'],
                collect($batch['summary'])->map(fn ($v, $k) => [$k, $v])->values()->all(),
            );
            $this->line('tracker: '.$batch['tracker_path']);
            foreach ($batch['results'] as $row) {
                $this->line(sprintf(
                    '%-42s %-14s http=%s code=%s sim=%s',
                    $row['operation_key'],
                    $row['classification'],
                    (string) ($row['http_status'] ?? '-'),
                    (string) ($row['error_code'] ?? '-'),
                    json_encode($row['simulated']),
                ));
            }
        }

        $passed = $batch['summary']['total'] > 0
            && array_sum(array_map(
                fn (string $status): int => $batch['summary'][$status] ?? 0,
                ['PASS_REAL_SYNC', 'PASS_REAL_EMPTY', 'PASS_REAL_ASYNC_COMPLETE', 'PASS_REAL_CACHE'],
            )) === $batch['summary']['total'];

        if (! $passed) {
            $this->error('Probe incompleto: toda operação alvo exige PASS_REAL_* com PRODUCTION_CANARY.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
