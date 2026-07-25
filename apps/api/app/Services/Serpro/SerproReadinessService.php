<?php

namespace App\Services\Serpro;

use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Enums\SerproReadinessGate;
use App\Enums\SerproReadinessScope;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\SerproReadinessEvidence;
use App\Models\SerproReadinessRun;
use App\Models\TaxProxyPower;
use App\Services\Serpro\Catalog\OperationCoverageMatrix;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Avaliação read-only de readiness hierárquico.
 *
 * NUNCA emite token OAuth, NUNCA envia Termo e NUNCA faz chamada fiscal implícita.
 * Distingue evidência offline (config/DB local) de live (handshake/OAuth prévio).
 */
final class SerproReadinessService
{
    public function __construct(
        private readonly SerproProductionEgressGate $egressGate,
        private readonly SerproExternalGateService $externalGates,
        private readonly SerproDocumentRegistry $documents,
        private readonly SerproKillSwitchService $killSwitch,
        private readonly SerproContractService $contracts,
        private readonly OperationCoverageMatrix $coverage,
    ) {}

    /**
     * @return SerproReadinessRun|array<string, mixed>
     */
    public function evaluateGlobal(
        ?SerproEnvironment $environment = null,
        bool $persist = true,
        ?int $actorUserId = null,
        string $trigger = 'MANUAL',
        bool $allowLive = false,
    ): SerproReadinessRun|array {
        $environment ??= $this->defaultEnvironment();
        $started = CarbonImmutable::now();
        $evidences = [];
        $highest = null;
        $liveAny = false;

        $add = function (
            SerproReadinessGate $gate,
            string $status,
            string $reason,
            bool $live = false,
            ?string $fingerprint = null,
            ?string $documentRevision = null,
            ?CarbonImmutable $validUntil = null,
            array $metadata = [],
        ) use (&$evidences, &$highest, &$liveAny): void {
            if ($live) {
                $liveAny = true;
            }
            $evidences[] = [
                'gate' => $gate,
                'scope' => SerproReadinessScope::Global,
                'status' => $status,
                'reason' => mb_substr($reason, 0, 500),
                'live' => $live,
                'fingerprint' => $fingerprint,
                'document_revision' => $documentRevision,
                'valid_until' => $validUntil,
                'metadata' => $metadata === [] ? null : $metadata,
            ];
            if ($status === 'PASS' && ($highest === null || $gate->rank() > $highest->rank())) {
                $highest = $gate;
            }
        };

        // CONFIGURED
        $drivers = config('serpro.capabilities', []);
        $configured = is_array($drivers) && $drivers !== [];
        $add(
            SerproReadinessGate::Configured,
            $configured ? 'PASS' : 'FAIL',
            $configured ? 'Config serpro.capabilities presente.' : 'Config de capacidades ausente.'
        );

        if ($this->killSwitch->isGlobalActive()) {
            $add(SerproReadinessGate::Configured, 'WARN', 'Kill switch global ativo (esperado em contenção).');
        }

        // Document registry (offline fingerprint)
        $manifestRevision = null;
        $manifestFingerprint = null;
        try {
            $manifest = $this->documents->loadManifest();
            $manifestRevision = (string) ($manifest['version'] ?? 'unknown');
            $manifestFingerprint = hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
            $add(
                SerproReadinessGate::Configured,
                'PASS',
                'Manifesto de fontes oficiais v'.$manifestRevision.' carregado.',
                fingerprint: substr($manifestFingerprint, 0, 64),
                documentRevision: $manifestRevision,
            );
        } catch (\Throwable) {
            $add(SerproReadinessGate::Configured, 'FAIL', 'Manifesto de fontes oficiais indisponível.');
        }

        // CREDENTIALS_ROTATED
        $prod = $this->egressGate->prodCheckSnapshot($environment);
        $exposedBlocking = $prod['exposed_blocking_versions'] !== [];
        $add(
            SerproReadinessGate::CredentialsRotated,
            $exposedBlocking ? 'FAIL' : 'PASS',
            $exposedBlocking
                ? 'Há versão de credencial exposta não RETIRED/COMPROMISED.'
                : 'Nenhuma versão exposta bloqueia o gate de rotação (ou não há versões).',
            metadata: ['exposed_count' => count($prod['exposed_blocking_versions'] ?? [])],
        );

        $contract = $this->contracts->activeFor($environment);
        $contractId = $contract?->id;
        $contractFp = $contract?->cert_fingerprint_sha256
            ?? ($contract !== null ? 'contract:'.$contract->id : null);

        if ($contract === null) {
            $add(SerproReadinessGate::Configured, 'WARN', 'Nenhum contrato ativo no ambiente.');
        } else {
            $add(
                SerproReadinessGate::Configured,
                'PASS',
                'Contrato ativo presente (metadados locais).',
                fingerprint: $contractFp,
                metadata: [
                    'contract_id' => $contract->id,
                    'environment' => $environment->value,
                    'status' => $contract->status->value ?? (string) $contract->status,
                ],
            );
        }

        // TLS_OK / OAUTH_OK — offline por default; live só se explicitamente permitido E evidência prévia
        $liveRequested = $allowLive && (bool) config('serpro.readiness.allow_live', false);
        if ($liveRequested) {
            $add(
                SerproReadinessGate::TlsOk,
                'SKIP',
                'Live TLS não embutido no readiness (use smoke dedicado).',
                live: false
            );
            $add(
                SerproReadinessGate::OauthOk,
                'SKIP',
                'Live OAuth não embutido no readiness (use smoke dedicado).',
                live: false
            );
        } else {
            $add(
                SerproReadinessGate::TlsOk,
                'SKIP',
                'Handshake TLS live não executado — evidência offline apenas.',
                live: false
            );
            $add(
                SerproReadinessGate::OauthOk,
                'SKIP',
                'OAuth mTLS live não executado — evidência offline apenas.',
                live: false
            );
        }

        // External gates block PRODUCTION_READY
        $this->externalGates->ensureBaselineGates();
        if ($this->externalGates->anyBlockingProduction()) {
            $add(
                SerproReadinessGate::ProductionReady,
                'FAIL',
                'Gates documentais externos ainda abertos.'
            );
        }

        $result = $this->computeResult($evidences);
        $ttlHours = max(1, (int) config('serpro.readiness.default_ttl_hours', 24));
        $summary = [
            'environment' => $environment->value,
            'serpro_contract_id' => $contractId,
            'contract_fingerprint' => $contractFp,
            'document_revision' => $manifestRevision,
            'document_fingerprint' => $manifestFingerprint !== null ? substr($manifestFingerprint, 0, 16) : null,
            'kill_switch' => $prod['kill_switch'] ?? null,
            'drivers' => $prod['drivers'] ?? null,
            'billable_egress_allowed' => $prod['billable_egress']['allowed'] ?? false,
            'issues' => $prod['issues'] ?? [],
            'live_evidence' => $liveAny,
            'evidence_mode' => $liveAny ? 'MIXED' : 'OFFLINE',
            'note' => 'Readiness offline: não emite token nem chama serviço fiscal.',
        ];

        if (! $persist) {
            return $this->arrayPayload(
                SerproReadinessScope::Global,
                $environment,
                $highest,
                $result,
                $liveAny,
                $evidences,
                $summary,
                officeId: null,
                clientId: null,
                operationKey: null,
                contractId: $contractId,
            );
        }

        return $this->persistRun(
            scope: SerproReadinessScope::Global,
            environment: $environment,
            highest: $highest,
            result: $result,
            liveEvidence: $liveAny,
            trigger: $trigger,
            actorUserId: $actorUserId,
            started: $started,
            expiresAt: $started->addHours($ttlHours),
            summary: $summary,
            evidences: $evidences,
            contractId: $contractId,
            officeId: null,
            clientId: null,
            operationKey: null,
        );
    }

    /**
     * @return SerproReadinessRun|array<string, mixed>
     */
    public function evaluateOffice(
        Office $office,
        ?SerproEnvironment $environment = null,
        bool $persist = true,
        ?int $actorUserId = null,
        string $trigger = 'MANUAL',
    ): SerproReadinessRun|array {
        $environment ??= $this->defaultEnvironment();
        $started = CarbonImmutable::now();
        $global = $this->evaluateGlobal($environment, persist: false, actorUserId: $actorUserId, trigger: $trigger);
        $globalArr = is_array($global) ? $global : $global->toSanitizedArray();

        $evidences = [];
        $highest = SerproReadinessGate::tryFrom((string) ($globalArr['highest_gate'] ?? '')) ?? null;
        $liveAny = (bool) ($globalArr['live_evidence'] ?? false);

        $add = function (
            SerproReadinessGate $gate,
            string $status,
            string $reason,
            bool $live = false,
            ?string $fingerprint = null,
        ) use (&$evidences, &$highest, &$liveAny): void {
            if ($live) {
                $liveAny = true;
            }
            $evidences[] = [
                'gate' => $gate,
                'scope' => SerproReadinessScope::Office,
                'status' => $status,
                'reason' => mb_substr($reason, 0, 500),
                'live' => $live,
                'fingerprint' => $fingerprint,
                'document_revision' => null,
                'valid_until' => null,
                'metadata' => null,
            ];
            if ($status === 'PASS' && ($highest === null || $gate->rank() > $highest->rank())) {
                $highest = $gate;
            }
        };

        $seg = $office->serpro_segregation_class;
        $demoBlocked = $seg === 'DEMO' || str_contains(strtolower((string) $office->slug), 'demo');
        $add(
            SerproReadinessGate::Configured,
            $demoBlocked ? 'FAIL' : 'PASS',
            $demoBlocked
                ? 'Office demo/segregado não pode usar endpoint real.'
                : 'Office elegível para avaliação tenant (não-demo).',
        );

        $auth = OfficeSerproAuthorization::query()
            ->where('office_id', $office->id)
            ->where('environment', $environment->value)
            ->first();

        if ($auth === null) {
            $add(SerproReadinessGate::TermLocalValid, 'FAIL', 'Autorização do escritório ausente.');
        } else {
            $status = $auth->status instanceof SerproAuthorizationStatus
                ? $auth->status
                : SerproAuthorizationStatus::tryFrom((string) $auth->status);

            // Offline: presença de metadados/hash, sem revalidar XML
            if ($auth->termo_sha256 || $auth->termo_vault_object_id) {
                $add(
                    SerproReadinessGate::TermLocalValid,
                    'PASS',
                    'Metadados de Termo presentes (validação local offline).',
                    fingerprint: $auth->termo_sha256 ? substr((string) $auth->termo_sha256, 0, 64) : null,
                );
            } else {
                $add(SerproReadinessGate::TermLocalValid, 'FAIL', 'Termo local ausente.');
            }

            $serproAccepted = $status === SerproAuthorizationStatus::TokenActive
                || $status === SerproAuthorizationStatus::TermValid
                || $auth->procurador_token_vault_object_id !== null;

            $add(
                SerproReadinessGate::TermSerproAccepted,
                $serproAccepted ? 'PASS' : 'FAIL',
                $serproAccepted
                    ? 'Indício de aceite SERPRO/token (evidência local, não live).'
                    : 'Sem indício de aceite SERPRO/token do procurador.',
                live: false,
            );
        }

        $contract = $this->contracts->activeFor($environment);
        $result = $demoBlocked ? 'FAIL' : $this->mergeResults(
            (string) ($globalArr['result'] ?? 'PARTIAL'),
            $this->computeResult($evidences),
        );

        $ttlHours = max(1, (int) config('serpro.readiness.default_ttl_hours', 24));
        $summary = [
            'environment' => $environment->value,
            'office_id' => $office->id,
            'office_segregation_class' => $seg,
            'demo_blocked_for_real' => $demoBlocked,
            'serpro_contract_id' => $contract?->id,
            'authorization_status' => isset($auth) && $auth !== null
                ? ($auth->status->value ?? (string) $auth->status)
                : null,
            'global_result' => $globalArr['result'] ?? null,
            'live_evidence' => $liveAny,
            'evidence_mode' => 'OFFLINE',
            'note' => 'Sem chamada fiscal implícita; Termo/token/poder offline via metadados locais.',
            // sem orçamento global na visão tenant
        ];

        if (! $persist) {
            $payload = $this->arrayPayload(
                SerproReadinessScope::Office,
                $environment,
                $highest,
                $result,
                $liveAny,
                $evidences,
                $summary,
                officeId: $office->id,
                clientId: null,
                operationKey: null,
                contractId: $contract?->id,
            );
            // Visão tenant: resumo global sanitizado (sem issues/orçamento)
            $payload['global'] = [
                'result' => $globalArr['result'] ?? null,
                'highest_gate' => $globalArr['highest_gate'] ?? null,
                'live_evidence' => (bool) ($globalArr['live_evidence'] ?? false),
                'summary' => [
                    'kill_switch' => data_get($globalArr, 'summary.kill_switch'),
                    'billable_egress_allowed' => (bool) data_get($globalArr, 'summary.billable_egress_allowed', false),
                    'live_evidence' => (bool) data_get($globalArr, 'summary.live_evidence', false),
                ],
            ];

            return $payload;
        }

        return $this->persistRun(
            scope: SerproReadinessScope::Office,
            environment: $environment,
            highest: $highest,
            result: $result,
            liveEvidence: $liveAny,
            trigger: $trigger,
            actorUserId: $actorUserId,
            started: $started,
            expiresAt: $started->addHours($ttlHours),
            summary: $summary,
            evidences: $evidences,
            contractId: $contract?->id,
            officeId: $office->id,
            clientId: null,
            operationKey: null,
        );
    }

    /**
     * @return SerproReadinessRun|array<string, mixed>
     */
    public function evaluateOperation(
        Office $office,
        string $operationKey,
        ?Client $client = null,
        ?SerproEnvironment $environment = null,
        bool $persist = true,
        ?int $actorUserId = null,
        string $trigger = 'MANUAL',
    ): SerproReadinessRun|array {
        $environment ??= $this->defaultEnvironment();
        $started = CarbonImmutable::now();
        $officeEval = $this->evaluateOffice($office, $environment, persist: false, actorUserId: $actorUserId, trigger: $trigger);
        $officeArr = is_array($officeEval) ? $officeEval : $officeEval->toSanitizedArray();

        $evidences = [];
        $highest = SerproReadinessGate::tryFrom((string) ($officeArr['highest_gate'] ?? '')) ?? null;
        $liveAny = false;

        $add = function (
            SerproReadinessGate $gate,
            string $status,
            string $reason,
            ?string $fingerprint = null,
        ) use (&$evidences, &$highest): void {
            $evidences[] = [
                'gate' => $gate,
                'scope' => SerproReadinessScope::Operation,
                'status' => $status,
                'reason' => mb_substr($reason, 0, 500),
                'live' => false,
                'fingerprint' => $fingerprint,
                'document_revision' => null,
                'valid_until' => null,
                'metadata' => null,
            ];
            if ($status === 'PASS' && ($highest === null || $gate->rank() > $highest->rank())) {
                $highest = $gate;
            }
        };

        if ($client !== null && (int) $client->office_id !== (int) $office->id) {
            $add(SerproReadinessGate::Configured, 'FAIL', 'Cliente não pertence ao office.');
        }

        $coverage = $this->coverage->evaluate($operationKey);
        $implemented = (bool) ($coverage['eligible_implemented'] ?? false);
        $add(
            SerproReadinessGate::Configured,
            $implemented ? 'PASS' : 'WARN',
            $implemented
                ? 'Operação com cobertura IMPLEMENTED na matriz offline.'
                : 'Operação sem cobertura IMPLEMENTED completa (fail-closed em produção).',
            fingerprint: isset($coverage['hash']) ? (string) $coverage['hash'] : null,
        );

        $powerOk = false;
        if ($client !== null) {
            $powerOk = TaxProxyPower::query()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->whereNull('closed_at')
                ->exists();
            $add(
                SerproReadinessGate::PowersVerified,
                $powerOk ? 'PASS' : 'FAIL',
                $powerOk
                    ? 'Há poder/procuração não encerrado para o cliente (evidência offline).'
                    : 'Sem poder/procuração ativo offline para o cliente.',
            );
        } else {
            $add(
                SerproReadinessGate::PowersVerified,
                'SKIP',
                'Cliente não informado — poderes por operação não avaliados.',
            );
        }

        $contract = $this->contracts->activeFor($environment);
        $result = $this->mergeResults(
            (string) ($officeArr['result'] ?? 'PARTIAL'),
            $this->computeResult($evidences),
        );
        $ttlHours = max(1, (int) config('serpro.readiness.default_ttl_hours', 24));
        $summary = [
            'environment' => $environment->value,
            'office_id' => $office->id,
            'client_id' => $client?->id,
            'operation_key' => $operationKey,
            'coverage_class' => $coverage['platform_support'] ?? null,
            'coverage_eligible_implemented' => (bool) ($coverage['eligible_implemented'] ?? false),
            'live_evidence' => false,
            'evidence_mode' => 'OFFLINE',
            'serpro_contract_id' => $contract?->id,
            'note' => 'Readiness de operação offline — sem chamada fiscal.',
        ];

        if (! $persist) {
            return $this->arrayPayload(
                SerproReadinessScope::Operation,
                $environment,
                $highest,
                $result,
                false,
                $evidences,
                $summary,
                officeId: $office->id,
                clientId: $client?->id,
                operationKey: $operationKey,
                contractId: $contract?->id,
            );
        }

        return $this->persistRun(
            scope: SerproReadinessScope::Operation,
            environment: $environment,
            highest: $highest,
            result: $result,
            liveEvidence: false,
            trigger: $trigger,
            actorUserId: $actorUserId,
            started: $started,
            expiresAt: $started->addHours($ttlHours),
            summary: $summary,
            evidences: $evidences,
            contractId: $contract?->id,
            officeId: $office->id,
            clientId: $client?->id,
            operationKey: $operationKey,
        );
    }

    private function defaultEnvironment(): SerproEnvironment
    {
        return SerproEnvironment::tryFrom(strtoupper((string) config('serpro.default_environment', 'TRIAL')))
            ?? SerproEnvironment::Trial;
    }

    /**
     * Resultado agregado: FAIL só se gates de fundação (CONFIGURED/CREDENTIALS_ROTATED)
     * falharem; falhas de PRODUCTION_READY/Termo etc. mantêm PARTIAL (escada hierárquica).
     *
     * @param  list<array<string, mixed>>  $evidences
     */
    private function computeResult(array $evidences): string
    {
        $foundationFail = false;
        $hasPass = false;
        foreach ($evidences as $e) {
            $gate = $e['gate'] ?? null;
            $gateValue = $gate instanceof SerproReadinessGate ? $gate->value : (string) $gate;
            $status = (string) ($e['status'] ?? '');

            if ($status === 'PASS') {
                $hasPass = true;
            }
            if ($status === 'FAIL' && in_array($gateValue, [
                SerproReadinessGate::Configured->value,
                SerproReadinessGate::CredentialsRotated->value,
            ], true)) {
                $foundationFail = true;
            }
        }

        if ($foundationFail) {
            return 'FAIL';
        }
        if ($hasPass) {
            return 'PARTIAL';
        }

        return 'UNKNOWN';
    }

    private function mergeResults(string $a, string $b): string
    {
        $rank = ['FAIL' => 0, 'UNKNOWN' => 1, 'PARTIAL' => 2, 'PASS' => 3];

        return ($rank[$a] ?? 1) <= ($rank[$b] ?? 1) ? $a : $b;
    }

    /**
     * @param  list<array<string, mixed>>  $evidences
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function arrayPayload(
        SerproReadinessScope $scope,
        SerproEnvironment $environment,
        ?SerproReadinessGate $highest,
        string $result,
        bool $liveEvidence,
        array $evidences,
        array $summary,
        ?int $officeId,
        ?int $clientId,
        ?string $operationKey,
        ?int $contractId,
    ): array {
        return [
            'scope' => $scope->value,
            'environment' => $environment->value,
            'serpro_contract_id' => $contractId,
            'office_id' => $officeId,
            'client_id' => $clientId,
            'operation_key' => $operationKey,
            'highest_gate' => $highest?->value,
            'result' => $result,
            'live_evidence' => $liveEvidence,
            'evidences' => array_map(static fn (array $e) => [
                'gate' => $e['gate'] instanceof SerproReadinessGate ? $e['gate']->value : (string) $e['gate'],
                'scope' => $e['scope'] instanceof SerproReadinessScope ? $e['scope']->value : (string) ($e['scope'] ?? $scope->value),
                'status' => $e['status'],
                'sanitized_reason' => $e['reason'],
                'live_evidence' => (bool) $e['live'],
                'fingerprint' => $e['fingerprint'],
                'document_revision' => $e['document_revision'] ?? null,
            ], $evidences),
            'summary' => $summary,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $evidences
     * @param  array<string, mixed>  $summary
     */
    private function persistRun(
        SerproReadinessScope $scope,
        SerproEnvironment $environment,
        ?SerproReadinessGate $highest,
        string $result,
        bool $liveEvidence,
        string $trigger,
        ?int $actorUserId,
        CarbonImmutable $started,
        CarbonImmutable $expiresAt,
        array $summary,
        array $evidences,
        ?int $contractId,
        ?int $officeId,
        ?int $clientId,
        ?string $operationKey,
    ): SerproReadinessRun {
        return DB::transaction(function () use (
            $scope,
            $environment,
            $highest,
            $result,
            $liveEvidence,
            $trigger,
            $actorUserId,
            $started,
            $expiresAt,
            $summary,
            $evidences,
            $contractId,
            $officeId,
            $clientId,
            $operationKey,
        ) {
            $run = SerproReadinessRun::query()->create([
                'scope' => $scope,
                'environment' => $environment,
                'serpro_contract_id' => $contractId,
                'office_id' => $officeId,
                'client_id' => $clientId,
                'operation_key' => $operationKey,
                'highest_gate' => $highest,
                'result' => $result,
                'live_evidence' => $liveEvidence,
                'trigger' => $trigger,
                'actor_user_id' => $actorUserId,
                'started_at' => $started,
                'finished_at' => now(),
                'expires_at' => $expiresAt,
                'summary' => $summary,
            ]);

            foreach ($evidences as $e) {
                SerproReadinessEvidence::query()->create([
                    'serpro_readiness_run_id' => $run->id,
                    'gate' => $e['gate'],
                    'scope' => $e['scope'] ?? $scope,
                    'status' => $e['status'],
                    'live_evidence' => (bool) $e['live'],
                    'fingerprint' => $e['fingerprint'],
                    'document_revision' => $e['document_revision'] ?? null,
                    'sanitized_reason' => $e['reason'],
                    'observed_at' => now(),
                    'valid_until' => $e['valid_until'] ?? null,
                    'metadata' => $e['metadata'] ?? null,
                ]);
            }

            return $run->load('evidences');
        });
    }
}
