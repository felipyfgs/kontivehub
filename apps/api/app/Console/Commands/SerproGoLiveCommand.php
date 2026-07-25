<?php

namespace App\Console\Commands;

use App\Enums\SerproEnvironment;
use App\Models\Office;
use App\Models\SerproRolloutApproval;
use App\Services\Serpro\SerproCircuitBreaker;
use App\Services\Serpro\SerproKillSwitchService;
use App\Services\Serpro\SerproReadinessPromotionService;
use App\Services\Serpro\SerproSmokeService;
use App\Services\Serpro\Usage\UsageAggregationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Operações de go-live controlado (sem chamar SERPRO fiscal).
 *
 * Subcomandos: checklist | free-smoke-promote | canary-blocked-check |
 * kill-switch-status | kill-switch-hydrate | breaker-status | ledger-dry-run
 */
class SerproGoLiveCommand extends Command
{
    protected $signature = 'serpro:go-live
        {action : checklist|free-smoke-promote|canary-blocked-check|kill-switch-status|kill-switch-hydrate|breaker-status|ledger-dry-run}
        {--serpro-env= : Ambiente SERPRO}
        {--office= : ID do Office (não versionar em OpenSpec)}
        {--confirm-ladder : Confirma escada gratuita completa}
        {--termo-local : Ladder: termo local OK}
        {--apoiar-ok : Ladder: /Apoiar OK}
        {--powers-verified : Ladder: poderes OK}
        {--monitorar-ok : Ladder: /Monitorar canário OK}
        {--zero-billable : Ladder: zero Consultar/Emitir/Declarar}
        {--kill-switch-tested : Ladder: kill switch testado}
        {--notes= : Notas sanitizadas}
        {--approval-id= : ID aprovação dual (canary)}
        {--operation-key= : Operação do canário faturável}
        {--max-unit-cost-micros=0 : Teto unitário do canário}
        {--max-quantity=0 : Quantidade máxima do canário}
        {--year= : Ano calendário ledger dry-run}
        {--month= : Mês calendário ledger dry-run}
        {--json : Saída JSON}';

    protected $description = 'Go-live SERPRO: checklist, FREE_SMOKE_OK, bloqueio canário, kill-switch, ledger dry-run';

    public function handle(
        SerproSmokeService $smoke,
        SerproReadinessPromotionService $promotion,
        SerproKillSwitchService $killSwitch,
        SerproCircuitBreaker $breaker,
        UsageAggregationService $aggregates,
    ): int {
        $action = strtolower((string) $this->argument('action'));

        try {
            return match ($action) {
                'checklist' => $this->doChecklist($smoke),
                'free-smoke-promote' => $this->doFreeSmoke($promotion),
                'canary-blocked-check' => $this->doCanaryBlocked($promotion),
                'kill-switch-status' => $this->doKillStatus($killSwitch),
                'kill-switch-hydrate' => $this->doKillHydrate($killSwitch),
                'breaker-status' => $this->doBreaker($breaker),
                'ledger-dry-run' => $this->doLedgerDryRun($aggregates),
                default => $this->invalid($action),
            };
        } catch (Throwable $e) {
            $this->error(mb_substr($e->getMessage(), 0, 400));

            return self::FAILURE;
        }
    }

    private function doChecklist(SerproSmokeService $smoke): int
    {
        $deploy = $smoke->cleanDeployChecklist();
        $status = $smoke->status($this->environment());

        $payload = [
            'deploy_checklist' => $deploy,
            'smoke_status' => $status,
            'docs' => [
                'credential_rotation' => 'docs/ops/runbooks/serpro-credential-rotation.md',
                'clean_deploy' => 'docs/ops/runbooks/serpro-clean-prod-deploy.md',
                'smoke' => 'docs/ops/runbooks/serpro-smoke.md',
                'free_smoke_ladder' => 'docs/ops/runbooks/serpro-free-smoke-ladder.md',
                'kill_switch' => 'docs/ops/runbooks/serpro-kill-switch.md',
                'rollout' => 'docs/ops/runbooks/serpro-go-live-rollout.md',
            ],
            'budgets_note' => 'Confirmar budgets monetários positivos (global/office/canary) na UI/API antes de qualquer live faturável.',
            'demo_note' => 'Dados demo devem permanecer segregation_class=DEMO e fora de allowlists reais.',
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $deploy['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function doFreeSmoke(SerproReadinessPromotionService $promotion): int
    {
        if (! $this->option('confirm-ladder')) {
            throw new RuntimeException('Use --confirm-ladder após executar a escada gratuita documentada.');
        }

        $office = null;
        if ($this->option('office')) {
            $office = Office::query()->find((int) $this->option('office'));
            if ($office === null) {
                throw new RuntimeException('Office não encontrado.');
            }
        }

        $ladder = [
            'termo_local' => (bool) $this->option('termo-local'),
            'apoiar_ok' => (bool) $this->option('apoiar-ok'),
            'powers_verified' => (bool) $this->option('powers-verified'),
            'monitorar_ok' => (bool) $this->option('monitorar-ok'),
            'zero_consultar_emitir_declarar' => (bool) $this->option('zero-billable'),
            'kill_switch_tested' => (bool) $this->option('kill-switch-tested'),
        ];

        $run = $promotion->promoteFreeSmokeOk(
            operatorConfirmsLadder: true,
            ladder: $ladder,
            office: $office,
            environment: $this->environment(),
            notes: $this->option('notes') ? (string) $this->option('notes') : null,
        );

        $payload = $run->toSanitizedArray();
        $this->assertNoSecrets(json_encode($payload) ?: '');
        $this->info('Promovido FREE_SMOKE_OK (CANARY_READY NÃO promovido). run_id='.$run->id);
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function doCanaryBlocked(SerproReadinessPromotionService $promotion): int
    {
        $approvalId = (int) ($this->option('approval-id') ?: 0);
        $scope = [
            'office_id' => $this->option('office') ? (int) $this->option('office') : null,
            'operation_key' => (string) ($this->option('operation-key') ?: ''),
            'max_unit_cost_micros' => (int) ($this->option('max-unit-cost-micros') ?: 0),
            'max_quantity' => (int) ($this->option('max-quantity') ?: 0),
        ];

        if ($approvalId <= 0) {
            // Demonstra bloqueio sem aprovação
            try {
                $fake = new SerproRolloutApproval([
                    'action' => SerproReadinessPromotionService::ACTION_BILLABLE_CANARY,
                    'status' => 'PENDING',
                ]);
                $promotion->promoteCanaryReady($fake, $scope);
                $this->error('FALHA DE SEGURANÇA: canário aceito sem aprovação.');

                return self::FAILURE;
            } catch (Throwable $e) {
                $payload = [
                    'canary_ready_blocked' => true,
                    'reason' => mb_substr($e->getMessage(), 0, 300),
                    'required' => [
                        'dual_approval_action' => SerproReadinessPromotionService::ACTION_BILLABLE_CANARY,
                        'max_unit_cost_micros' => '>0',
                        'max_quantity' => 1,
                        'operation_key' => 'read-only delimitada',
                        'office_id' => 'selecionado na aplicação (não no OpenSpec)',
                    ],
                ];
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }
        }

        $approval = SerproRolloutApproval::query()->find($approvalId);
        if ($approval === null) {
            throw new RuntimeException('Aprovação não encontrada.');
        }

        $run = $promotion->promoteCanaryReady($approval, $scope, $this->environment());
        $this->info('CANARY_READY promovido sob aprovação dual. run_id='.$run->id);
        $this->line(json_encode($run->toSanitizedArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function doKillStatus(SerproKillSwitchService $killSwitch): int
    {
        $payload = $killSwitch->status();
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function doKillHydrate(SerproKillSwitchService $killSwitch): int
    {
        // Simula segurança pós-flush Redis: rehidrata do DB durable.
        Cache::forget('serpro.kill_switch.global');
        $killSwitch->hydrateCacheFromDurable();
        $payload = [
            'hydrated' => true,
            'status' => $killSwitch->status(),
            'note' => 'Flush Redis não reabre kill switch durable (serpro_runtime_controls).',
        ];
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function doBreaker(SerproCircuitBreaker $breaker): int
    {
        // status público via reflexão de API — usar isCallAllowed como proxy
        $payload = [
            'global_call_allowed' => $breaker->isCallAllowed(null),
            'note' => 'Breaker segmentado; 403 de negócio não tripam. Ver serpro_circuit_breaker_states.',
            'config' => [
                'failure_threshold' => (int) config('serpro.circuit_breaker.failure_threshold'),
                'open_seconds' => (int) config('serpro.circuit_breaker.open_seconds'),
                'half_open_max_probes' => (int) config('serpro.circuit_breaker.half_open_max_probes'),
            ],
        ];
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function doLedgerDryRun(UsageAggregationService $aggregates): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $month = (int) ($this->option('month') ?: now()->month);

        // Apenas leitura de totais internos — não grava reconciliação nem chama SERPRO.
        $internal = method_exists($aggregates, 'internalEstimatedTotalMicros')
            ? $aggregates->internalEstimatedTotalMicros($year, $month)
            : 0;

        $payload = [
            'dry_run' => true,
            'period_year' => $year,
            'period_month' => $month,
            'internal_total_estimated_cost_micros' => $internal,
            'official_total_not_imported' => true,
            'writes' => false,
            'note' => 'Dry-run: sem importar fatura oficial e sem criar serpro_usage_reconciliations. Compare com extrato offline.',
            'billable_routes_in_smoke' => false,
        ];
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function environment(): SerproEnvironment
    {
        $envRaw = $this->option('serpro-env')
            ?: (string) config('serpro.default_environment', 'TRIAL');

        return SerproEnvironment::tryFrom(strtoupper((string) $envRaw))
            ?? SerproEnvironment::Trial;
    }

    private function invalid(string $action): int
    {
        $this->error('Ação inválida: '.$action);

        return self::FAILURE;
    }

    private function assertNoSecrets(string $payload): void
    {
        foreach (['BEGIN CERTIFICATE', 'consumer_secret', '-----BEGIN PRIVATE'] as $needle) {
            if (str_contains($payload, $needle)) {
                throw new RuntimeException('Saída contém possível segredo.');
            }
        }
    }
}
