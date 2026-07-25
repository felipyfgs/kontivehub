<?php

namespace App\Services\Serpro;

use App\Enums\SerproEnvironment;
use App\Enums\SerproReadinessGate;
use App\Enums\SerproReadinessScope;
use App\Models\Office;
use App\Models\SerproReadinessEvidence;
use App\Models\SerproReadinessRun;
use App\Models\SerproRolloutApproval;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Promoção controlada de gates de readiness.
 *
 * - FREE_SMOKE_OK: permitido após escada gratuita (sem Consultar/Emitir/Declarar).
 * - CANARY_READY: bloqueado sem aprovação dual + teto unitário + quantidade 1.
 * - PRODUCTION_READY: fora do escopo deste go-live (sempre bloqueado aqui).
 */
final class SerproReadinessPromotionService
{
    public const ACTION_FREE_SMOKE_PROMOTE = 'FREE_SMOKE_PROMOTE';

    public const ACTION_BILLABLE_CANARY = 'BILLABLE_CANARY';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SerproContractService $contracts,
    ) {}

    /**
     * Registra evidência live de um gate global (TLS_OK / OAUTH_OK).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordLiveGate(
        SerproReadinessGate $gate,
        string $status,
        string $reason,
        ?SerproEnvironment $environment = null,
        ?int $actorUserId = null,
        bool $live = true,
        ?string $fingerprint = null,
        array $metadata = [],
        string $trigger = 'SMOKE',
        ?int $officeId = null,
    ): SerproReadinessRun {
        if (! in_array($gate, [
            SerproReadinessGate::TlsOk,
            SerproReadinessGate::OauthOk,
            SerproReadinessGate::TermLocalValid,
            SerproReadinessGate::TermSerproAccepted,
            SerproReadinessGate::PowersVerified,
            SerproReadinessGate::FreeSmokeOk,
        ], true)) {
            throw new RuntimeException(
                'recordLiveGate não promove '.$gate->value.'; use promote* dedicados para canário/produção.'
            );
        }

        if ($gate === SerproReadinessGate::CanaryReady
            || $gate === SerproReadinessGate::ProductionReady
        ) {
            throw new RuntimeException('Gate '.$gate->value.' exige fluxo de promoção dedicado com quatro olhos.');
        }

        $environment ??= $this->defaultEnvironment();
        $started = CarbonImmutable::now();
        $ttlHours = max(1, (int) config('serpro.readiness.default_ttl_hours', 24));
        $contract = $this->contracts->activeFor($environment);
        $scope = $officeId !== null ? SerproReadinessScope::Office : SerproReadinessScope::Global;

        $highest = $status === 'PASS' ? $gate : null;

        return DB::transaction(function () use (
            $gate,
            $status,
            $reason,
            $environment,
            $actorUserId,
            $live,
            $fingerprint,
            $metadata,
            $trigger,
            $officeId,
            $started,
            $ttlHours,
            $contract,
            $scope,
            $highest,
        ): SerproReadinessRun {
            $run = SerproReadinessRun::query()->create([
                'scope' => $scope,
                'environment' => $environment,
                'serpro_contract_id' => $contract?->id,
                'office_id' => $officeId,
                'client_id' => null,
                'operation_key' => null,
                'highest_gate' => $highest,
                'result' => $status === 'PASS' ? 'PARTIAL' : 'FAIL',
                'live_evidence' => $live,
                'trigger' => $trigger,
                'actor_user_id' => $actorUserId,
                'started_at' => $started,
                'finished_at' => now(),
                'expires_at' => $started->addHours($ttlHours),
                'summary' => $this->audit->redact([
                    'promoted_gate' => $gate->value,
                    'live' => $live,
                    'note' => 'Evidência de smoke/promoção; sem rota faturável implícita.',
                    'metadata' => $metadata,
                ]),
            ]);

            SerproReadinessEvidence::query()->create([
                'serpro_readiness_run_id' => $run->id,
                'gate' => $gate,
                'scope' => $scope,
                'status' => $status,
                'live_evidence' => $live,
                'fingerprint' => $fingerprint !== null ? substr($fingerprint, 0, 64) : null,
                'document_revision' => null,
                'sanitized_reason' => mb_substr($reason, 0, 500),
                'observed_at' => now(),
                'valid_until' => $started->addHours($ttlHours),
                'metadata' => $metadata === [] ? null : $this->audit->redact($metadata),
            ]);

            $this->audit->record('serpro.readiness.gate_evidence', 'SUCCESS', $run, [
                'gate' => $gate->value,
                'status' => $status,
                'live' => $live,
            ], $actorUserId, $officeId);

            return $run->load('evidences');
        });
    }

    /**
     * Promove somente FREE_SMOKE_OK (teto da escada gratuita).
     *
     * @param  array{
     *   termo_local?: bool,
     *   apoiar_ok?: bool,
     *   powers_verified?: bool,
     *   monitorar_ok?: bool,
     *   zero_consultar_emitir_declarar?: bool,
     *   kill_switch_tested?: bool
     * }  $ladder
     */
    public function promoteFreeSmokeOk(
        bool $operatorConfirmsLadder,
        array $ladder,
        ?Office $office = null,
        ?SerproEnvironment $environment = null,
        ?int $actorUserId = null,
        ?string $notes = null,
    ): SerproReadinessRun {
        if (! $operatorConfirmsLadder) {
            throw new RuntimeException('Promoção FREE_SMOKE_OK exige confirmação explícita da escada gratuita.');
        }

        $required = [
            'termo_local',
            'apoiar_ok',
            'powers_verified',
            'monitorar_ok',
            'zero_consultar_emitir_declarar',
        ];
        foreach ($required as $key) {
            if (empty($ladder[$key])) {
                throw new RuntimeException(
                    "Escada FREE_SMOKE incompleta: marque {$key}=true (evidência operacional, sem identidade em artefato)."
                );
            }
        }

        if ($office !== null) {
            $seg = (string) ($office->serpro_segregation_class ?? '');
            $slug = strtolower((string) $office->slug);
            if ($seg === 'DEMO' || str_contains($slug, 'demo')) {
                throw new RuntimeException('Office demo/segregado não pode promover FREE_SMOKE_OK real.');
            }
        }

        $environment ??= $this->defaultEnvironment();
        $run = $this->recordLiveGate(
            SerproReadinessGate::FreeSmokeOk,
            'PASS',
            'Escada gratuita concluída; sem Consultar/Emitir/Declarar. Canário faturável NÃO promovido.',
            environment: $environment,
            actorUserId: $actorUserId,
            live: true,
            fingerprint: null,
            metadata: [
                'ladder' => [
                    'termo_local' => true,
                    'apoiar_ok' => true,
                    'powers_verified' => true,
                    'monitorar_ok' => true,
                    'zero_consultar_emitir_declarar' => true,
                    'kill_switch_tested' => (bool) ($ladder['kill_switch_tested'] ?? false),
                ],
                'office_id' => $office?->id,
                'notes' => $notes !== null ? mb_substr($notes, 0, 200) : null,
                'canary_ready' => false,
                'production_ready' => false,
            ],
            trigger: 'FREE_SMOKE_PROMOTE',
            officeId: $office?->id,
        );

        // Garantir highest_gate = FREE_SMOKE_OK
        $run->forceFill([
            'highest_gate' => SerproReadinessGate::FreeSmokeOk,
            'result' => 'PARTIAL',
        ])->save();

        $this->audit->record('serpro.readiness.free_smoke_ok', 'SUCCESS', $run, [
            'office_id' => $office?->id,
            'environment' => $environment->value,
            'canary_blocked' => true,
        ], $actorUserId, $office?->id);

        return $run->refresh()->load('evidences');
    }

    /**
     * CANARY_READY — bloqueado sem aprovação dual + teto unitário + qty 1.
     *
     * @param  array<string, mixed>  $scope  office_id, operation_key, max_unit_cost_micros, max_quantity, window_minutes
     */
    public function promoteCanaryReady(
        SerproRolloutApproval $approval,
        array $scope,
        ?SerproEnvironment $environment = null,
        ?int $actorUserId = null,
    ): SerproReadinessRun {
        if ($approval->action !== self::ACTION_BILLABLE_CANARY
            && $approval->action !== SerproRolloutApprovalService::ACTION_ROLLOUT_PROMOTE
        ) {
            throw new RuntimeException(
                'CANARY_READY exige aprovação action=BILLABLE_CANARY (recebido: '.$approval->action.').'
            );
        }

        // Canário/promoção: política DUAL_ROLE (Proprietário + Office ADMIN ou dual de promoção).
        // Confirmação OWNER singleton NUNCA satisfaz este gate.
        if ($approval->policy()->value === 'OWNER_CONFIRMATION' || ! $approval->isFullyApproved()) {
            throw new RuntimeException(
                'CANARY_READY bloqueado: exige política DUAL_ROLE completa (Proprietário + Office ADMIN distintos); confirmação singleton não satisfaz.'
            );
        }

        if (in_array((string) $approval->status, ['PENDING', 'PARTIAL', 'REJECTED', 'EXPIRED', ''], true)) {
            throw new RuntimeException(
                'CANARY_READY bloqueado: aprovação dual incompleta (status='.$approval->status.').'
            );
        }

        $maxCost = (int) ($scope['max_unit_cost_micros'] ?? 0);
        $maxQty = (int) ($scope['max_quantity'] ?? 0);
        $operationKey = (string) ($scope['operation_key'] ?? '');
        $officeId = isset($scope['office_id']) ? (int) $scope['office_id'] : null;

        if ($maxCost <= 0) {
            throw new RuntimeException('CANARY_READY bloqueado: max_unit_cost_micros deve ser > 0 (teto unitário).');
        }
        if ($maxQty !== 1) {
            throw new RuntimeException('CANARY_READY bloqueado: max_quantity deve ser exatamente 1.');
        }
        if ($operationKey === '') {
            throw new RuntimeException('CANARY_READY bloqueado: operation_key obrigatória e delimitada.');
        }
        if ($this->isMutatingOrForbiddenOperation($operationKey)) {
            throw new RuntimeException(
                'CANARY_READY bloqueado: operação mutante/proibida neste go-live ('.$operationKey.').'
            );
        }
        if ($officeId === null || $officeId <= 0) {
            throw new RuntimeException('CANARY_READY bloqueado: office_id do canário obrigatório (não versionar em OpenSpec).');
        }

        $environment ??= $this->defaultEnvironment();
        $started = CarbonImmutable::now();
        $ttlHours = max(1, (int) config('serpro.readiness.default_ttl_hours', 24));
        $contract = $this->contracts->activeFor($environment);

        return DB::transaction(function () use (
            $approval,
            $scope,
            $environment,
            $actorUserId,
            $started,
            $ttlHours,
            $contract,
            $officeId,
            $operationKey,
            $maxCost,
            $maxQty,
        ): SerproReadinessRun {
            $run = SerproReadinessRun::query()->create([
                'scope' => SerproReadinessScope::Office,
                'environment' => $environment,
                'serpro_contract_id' => $contract?->id,
                'office_id' => $officeId,
                'client_id' => isset($scope['client_id']) ? (int) $scope['client_id'] : null,
                'operation_key' => $operationKey,
                'highest_gate' => SerproReadinessGate::CanaryReady,
                'result' => 'PARTIAL',
                'live_evidence' => true,
                'trigger' => 'BILLABLE_CANARY_PROMOTE',
                'actor_user_id' => $actorUserId,
                'started_at' => $started,
                'finished_at' => now(),
                'expires_at' => $started->addHours(min($ttlHours, 4)),
                'summary' => $this->audit->redact([
                    'promoted_gate' => SerproReadinessGate::CanaryReady->value,
                    'approval_id' => $approval->id,
                    'max_unit_cost_micros' => $maxCost,
                    'max_quantity' => $maxQty,
                    'window_minutes' => (int) ($scope['window_minutes'] ?? 60),
                    'note' => 'Canário faturável delimitado; reconciliação posterior obrigatória.',
                ]),
            ]);

            SerproReadinessEvidence::query()->create([
                'serpro_readiness_run_id' => $run->id,
                'gate' => SerproReadinessGate::CanaryReady,
                'scope' => SerproReadinessScope::Office,
                'status' => 'PASS',
                'live_evidence' => true,
                'fingerprint' => null,
                'document_revision' => null,
                'sanitized_reason' => 'Aprovação dual + teto unitário satisfeitos para canário faturável.',
                'observed_at' => now(),
                'valid_until' => $started->addHours(min($ttlHours, 4)),
                'metadata' => $this->audit->redact([
                    'approval_id' => $approval->id,
                    'max_unit_cost_micros' => $maxCost,
                    'max_quantity' => $maxQty,
                    'operation_key' => $operationKey,
                ]),
            ]);

            $this->audit->record('serpro.readiness.canary_ready', 'SUCCESS', $run, [
                'approval_id' => $approval->id,
                'office_id' => $officeId,
                'operation_key' => $operationKey,
                'max_unit_cost_micros' => $maxCost,
            ], $actorUserId, $officeId);

            return $run->load('evidences');
        });
    }

    /**
     * Bloqueia promoção acidental de CANARY_READY / PRODUCTION_READY sem fluxo.
     */
    public function assertNotAutoPromotingBeyondFreeSmoke(?SerproReadinessGate $gate): void
    {
        if ($gate === null) {
            return;
        }
        if ($gate->rank() > SerproReadinessGate::FreeSmokeOk->rank()) {
            throw new RuntimeException(
                'Gate '.$gate->value.' não pode ser auto-promovido; exige aprovação operacional separada.'
            );
        }
    }

    private function isMutatingOrForbiddenOperation(string $operationKey): bool
    {
        $upper = strtoupper($operationKey);

        return str_contains($upper, 'EMITIR')
            || str_contains($upper, 'DECLARAR')
            || str_contains($upper, '/EMITIR')
            || str_contains($upper, '/DECLARAR');
    }

    private function defaultEnvironment(): SerproEnvironment
    {
        return SerproEnvironment::tryFrom(strtoupper((string) config('serpro.default_environment', 'TRIAL')))
            ?? SerproEnvironment::Trial;
    }
}
