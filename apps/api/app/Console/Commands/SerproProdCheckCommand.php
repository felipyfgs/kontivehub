<?php

namespace App\Console\Commands;

use App\Enums\SerproEnvironment;
use App\Models\SerproContract;
use App\Services\Serpro\SerproCredentialVersionService;
use App\Services\Serpro\SerproDocumentRegistry;
use App\Services\Serpro\SerproExternalGateService;
use App\Services\Serpro\SerproProductionEgressGate;
use Illuminate\Console\Command;

/**
 * Preflight de contenção/produção SERPRO (sem egress fiscal).
 *
 * Bloqueia (exit != 0) quando credencial exposta não está RETIRED/COMPROMISED
 * ou quando checks de drivers/fake em production falham.
 */
class SerproProdCheckCommand extends Command
{
    protected $signature = 'serpro:prod-check
        {--serpro-env= : Ambiente SERPRO (default: config serpro.default_environment)}
        {--mark-exposed : Marca contratos ACTIVE/PENDING legados como credencial exposta}
        {--allow-containment : Permite contenção fiscal explícita sem liberar egress faturável}
        {--json : Saída JSON sanitizada}';

    protected $description = 'Prod-check SERPRO: kill switch, drivers, credenciais expostas e egress faturável';

    public function handle(
        SerproProductionEgressGate $gate,
        SerproExternalGateService $externalGates,
        SerproDocumentRegistry $documents,
        SerproCredentialVersionService $versions,
    ): int {
        $envRaw = $this->option('serpro-env')
            ?: (string) config('serpro.default_environment', 'TRIAL');
        $environment = SerproEnvironment::tryFrom(strtoupper((string) $envRaw))
            ?? SerproEnvironment::Trial;

        $externalGates->ensureBaselineGates();

        if ($this->option('mark-exposed')) {
            $reason = 'Contenção go-live: material exposto durante configuração; rotação obrigatória.';
            $count = 0;
            SerproContract::query()
                ->where('environment', $environment->value)
                ->whereIn('status', ['ACTIVE', 'PENDING', 'BLOCKED'])
                ->orderBy('id')
                ->each(function (SerproContract $contract) use ($versions, $reason, &$count): void {
                    $versions->markContractCredentialsExposed($contract, $reason);
                    $count++;
                });
            $this->warn("Marcadas {$count} versão(ões)/contrato(s) como expostas (sem apagar histórico).");
        }

        try {
            $documents->syncFromManifest();
        } catch (\Throwable) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'status' => 'FAIL',
                    'issue' => 'SERPRO_OFFICIAL_SOURCE_INTEGRITY',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('FAIL: integridade das fontes oficiais SERPRO não comprovada.');
            }

            return self::FAILURE;
        }

        $snapshot = $gate->prodCheckSnapshot($environment);

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('SERPRO prod-check — env='.$environment->value);
            $this->table(
                ['check', 'detail'],
                collect($snapshot['billable_egress']['checks'] ?? [])->map(fn (array $c) => [
                    ($c['ok'] ? 'OK' : 'FAIL').' '.$c['id'],
                    $c['detail'],
                ])->all()
            );

            if ($snapshot['issues'] !== []) {
                $this->error('Issues:');
                foreach ($snapshot['issues'] as $issue) {
                    $this->line(' - '.$issue);
                }
            } else {
                $this->info('Nenhum issue bloqueante para o snapshot atual.');
            }

            $this->line('Drivers: '.json_encode($snapshot['drivers'], JSON_UNESCAPED_UNICODE));
            $this->line('Kill switch: '.json_encode($snapshot['kill_switch'], JSON_UNESCAPED_UNICODE));
            $this->line('External gates open: '.count($snapshot['external_gates_open']));
        }

        // Sempre falha se houver versão exposta bloqueando (objetivo da task 1.1).
        if ($snapshot['exposed_blocking_versions'] !== []) {
            $this->error('FAIL: credencial exposta não RETIRED/COMPROMISED — egress faturável bloqueado.');

            return self::FAILURE;
        }

        // Contrato legado com flag exposta sem versão terminal.
        foreach ($snapshot['issues'] as $issue) {
            if (str_contains($issue, 'credentials_exposed') || str_contains($issue, 'exposta')) {
                $this->error('FAIL: '.$issue);

                return self::FAILURE;
            }
        }

        // Em testing, ausência de versões expostas com issues vazios = success.
        // Em production (ou --strict implícito via APP_ENV), fail se issues, salvo
        // contenção fiscal explicitamente autorizada pelo caller operacional.
        $strict = app()->environment('production')
            || (bool) config('serpro.prod_check_strict', false);

        if ($strict && $snapshot['issues'] !== []) {
            if ($this->option('allow-containment') && $this->issuesAreContainmentOnly($snapshot['issues'])) {
                return self::SUCCESS;
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $issues
     */
    private function issuesAreContainmentOnly(array $issues): bool
    {
        if ($issues === []) {
            return true;
        }

        foreach ($issues as $issue) {
            if (str_starts_with($issue, 'Egress faturável bloqueado: Kill switch global SERPRO ativo.')) {
                continue;
            }

            if (str_starts_with($issue, 'Egress faturável bloqueado: Gates documentais abertos:')) {
                continue;
            }

            return false;
        }

        return true;
    }
}
