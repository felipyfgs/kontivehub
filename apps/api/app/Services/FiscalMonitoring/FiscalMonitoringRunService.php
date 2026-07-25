<?php

namespace App\Services\FiscalMonitoring;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Fiscal\FiscalPersistPayload;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalModuleAvailabilityState;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Enums\MonitorCommercialOrigin;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalCompetence;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalMonitoringSchedule;
use App\Models\MonitorCommercialLedgerEntry;
use App\Models\Office;
use App\Services\Audit\AuditLogger;
use App\Services\Fiscal\Availability\FiscalModuleAvailabilityService;
use App\Services\Fiscal\Availability\FiscalModuleControlService;
use App\Services\Fiscal\Availability\FiscalOperationClassifier;
use App\Services\Fiscal\Dctfweb\DctfwebCommunicationService;
use App\Services\Fiscal\Dctfweb\MitCommunicationService;
use App\Services\Fiscal\Fgts\FgtsCommunicationService;
use App\Services\Fiscal\ManualConsult\ManualConsultExecutionContext;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdCommunicationService;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPagtowebReconciliationService;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPostConsultService;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdRbt12Service;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiCommunicationService;
use App\Services\Fiscal\Sitfis\SitfisCommunicationService;
use App\Services\Operations\OperationsMetrics;
use App\Services\Operations\StructuredLogger;
use App\Services\Platform\OfficeSubscriptionGate;
use App\Services\Usage\CommercialMonitorCatalog;
use App\Services\Usage\MonitorCommercialLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ciclo de vida de execuções: criação idempotente, revalidação, orquestração e requeue.
 */
final class FiscalMonitoringRunService
{
    public function __construct(
        private readonly FiscalAdapterRegistry $registry,
        private readonly FiscalSnapshotPersistence $persistence,
        private readonly FiscalMonitoringScheduler $scheduler,
        private readonly OfficeSubscriptionGate $subscriptionGate,
        private readonly AuditLogger $audit,
        private readonly StructuredLogger $structuredLog,
        private readonly OperationsMetrics $metrics,
        private readonly MonitorCommercialLedgerService $commercialLedger,
        private readonly PgdasdPostConsultService $pgdasdPostConsult,
        private readonly PgdasdPagtowebReconciliationService $pgdasdPaymentReconciliation,
        private readonly PgdasdRbt12Service $pgdasdRbt12,
        private readonly FiscalModuleAvailabilityService $availability,
        private readonly FiscalModuleControlService $moduleControls,
        private readonly ManualConsultExecutionContext $manualConsultContext,
        private readonly SitfisProjectionReconciler $sitfisProjectionReconciler,
    ) {}

    /**
     * @return LengthAwarePaginator<int, FiscalMonitoringRun>
     */
    public function paginate(Office $office, int $perPage = 50, ?int $clientId = null, ?string $status = null): LengthAwarePaginator
    {
        $q = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->orderByDesc('id');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($status !== null) {
            $q->where('status', $status);
        }

        return $q->paginate($perPage);
    }

    public function findForOffice(Office $office, int $runId): ?FiscalMonitoringRun
    {
        return FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($runId)
            ->first();
    }

    /**
     * Cria (ou reutiliza) execução manual idempotente.
     */
    public function enqueueManual(
        Office $office,
        Client $client,
        string $systemCode,
        string $serviceCode,
        string $operationCode = 'MONITOR',
        ?FiscalCompetence $competence = null,
        ?int $actorId = null,
        ?string $correlationId = null,
        bool $dispatch = true,
    ): FiscalMonitoringRun {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }

        $correlationId ??= (string) Str::uuid();
        $slot = FiscalIdempotency::manualSlot($correlationId);
        $key = FiscalIdempotency::runKey(
            (int) $office->id,
            (int) $client->id,
            $systemCode,
            $serviceCode,
            $operationCode,
            $competence?->period_key,
            FiscalTrigger::Manual,
            $slot,
        );

        $run = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('idempotency_key', $key)
            ->first();

        $manualAction = $this->manualConsultContext->activeAction();
        if ($run !== null) {
            if ($manualAction !== null) {
                $progress = is_array($run->progress) ? $run->progress : [];
                $progress['manual_consult'] = true;
                $progress['action_id'] = $manualAction->actionId;
                $run->forceFill([
                    'operation_key' => $manualAction->operationKey,
                    'progress' => $progress,
                ])->save();
            }

            return $run;
        }

        $progress = $manualAction !== null
            ? [
                'manual_consult' => true,
                'action_id' => $manualAction->actionId,
            ]
            : null;

        $run = FiscalMonitoringRun::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'competence_id' => $competence?->id,
            'system_code' => strtoupper($systemCode),
            'service_code' => strtoupper($serviceCode),
            'operation_code' => strtoupper($operationCode),
            'operation_key' => $manualAction?->operationKey,
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => $key,
            'status' => FiscalRunStatus::Queued,
            'situation' => FiscalSituation::Unknown,
            'coverage' => 'UNKNOWN',
            'mutability' => FiscalMutability::ReadOnly,
            'correlation_id' => $correlationId,
            'progress' => $progress,
            'triggered_by' => $actorId,
        ]);

        if ($dispatch) {
            ExecuteFiscalMonitoringRunJob::dispatch($run->id)
                ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
        }

        return $run;
    }

    public function blockBeforeExecution(int $runId, string $code, string $message): FiscalMonitoringRun
    {
        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->find($runId);
        if ($run === null) {
            throw new RuntimeException("Run fiscal #{$runId} não encontrada.");
        }
        if ($run->status->isTerminal()) {
            return $run;
        }

        return $this->markBlocked($run, $code, $message);
    }

    /**
     * Fecha run não-terminal após falha não tratada do worker (Horizon failed()).
     */
    public function failUnhandledJob(int $runId, ?string $sanitizedMessage = null): ?FiscalMonitoringRun
    {
        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->find($runId);
        if ($run === null || $run->status->isTerminal()) {
            return $run;
        }

        $message = is_string($sanitizedMessage) && trim($sanitizedMessage) !== ''
            ? mb_substr(trim($sanitizedMessage), 0, 500)
            : 'Falha não tratada na execução da consulta fiscal.';

        return $this->markFailed($run, 'JOB_UNHANDLED_EXCEPTION', $message);
    }

    /**
     * Executa a run: revalida elegibilidade, chama adapter, persiste atomicamente.
     */
    /**
     * @param  array<string, mixed>  $transientContext
     */
    public function execute(int $runId, array $transientContext = []): FiscalMonitoringRun
    {
        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->find($runId);
        if ($run === null) {
            throw new RuntimeException("Run fiscal #{$runId} não encontrada.");
        }

        // REQUEUED é terminal para este id (progresso salvo + continuação enfileirada).
        if ($run->status->isTerminal()) {
            return $run;
        }

        $lock = Cache::lock(
            FiscalIdempotency::runLockKey((int) $run->office_id, $run->idempotency_key),
            (int) config('fiscal_monitoring.job.lock_ttl_seconds', 360),
        );

        if (! $lock->get()) {
            return $run;
        }

        $slots = null;

        try {
            $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->findOrFail($runId);
            $office = Office::query()->find($run->office_id);
            $client = Client::query()->withoutGlobalScopes()
                ->where('office_id', $run->office_id)
                ->whereKey($run->client_id)
                ->first();

            if ($office === null || $client === null) {
                return $this->markBlocked($run, 'OFFICE_OR_CLIENT_MISSING', 'Escritório ou cliente ausente.');
            }

            // Revalidação imediata pré-chamada
            $block = $this->preflightBlockReason($office, $client, $run);
            if ($block !== null) {
                return $this->markBlocked($run, $block['code'], $block['message']);
            }

            $slots = $this->scheduler->acquireSlots((int) $office->id, (int) config('fiscal_monitoring.job.lock_ttl_seconds', 360));
            if ($slots === null) {
                if ($transientContext !== []) {
                    return $this->markFailed(
                        $run,
                        'EPHEMERAL_CONTEXT_RETRY_FORBIDDEN',
                        'A solicitação manual não pôde ser executada agora; informe o dado novamente.',
                    );
                }
                // Sem slot: requeue leve sem consumir adapter
                $run->forceFill([
                    'status' => FiscalRunStatus::Queued,
                    'skip_reason' => 'RATE_LIMITED',
                ])->save();
                ExecuteFiscalMonitoringRunJob::dispatch($run->id)
                    ->delay(now()->addSeconds(15 + random_int(0, 15)))
                    ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

                return $run->fresh();
            }

            $owner = (string) Str::uuid();
            $run->forceFill([
                'status' => FiscalRunStatus::Running,
                'started_at' => $run->started_at ?? CarbonImmutable::now(),
                'lease_owner' => $owner,
                'locked_at' => CarbonImmutable::now(),
                'situation' => FiscalSituation::Processing,
            ])->save();

            $request = new FiscalAdapterRequest(
                office: $office,
                client: $client,
                run: $run,
                systemCode: $run->system_code,
                serviceCode: $run->service_code,
                operationCode: $run->operation_code,
                trigger: $run->trigger,
                competence: $run->competence_id
                    ? FiscalCompetence::query()->withoutGlobalScopes()->find($run->competence_id)
                    : null,
                progressCursor: $run->progress_cursor,
                progress: $run->progress ?? [],
                context: $transientContext,
            );

            $adapter = $this->registry->resolve($request);

            $module = $adapter->moduleKey();
            $operationClass = FiscalOperationClassifier::forMonitoring(
                $adapter->mutability(),
                (string) $run->system_code,
                (string) $run->service_code,
                (string) $run->operation_code,
            );
            $decision = $module === null
                ? null
                : $this->availability->resolve($module, $office, $operationClass);

            if ($decision !== null && ! $decision->allowed) {
                if (in_array($decision->state, [
                    FiscalModuleAvailabilityState::GloballyRestricted,
                    FiscalModuleAvailabilityState::OfficeRestricted,
                ], true)) {
                    $this->moduleControls->recordBlockedJob(
                        $decision->module,
                        $office,
                        $decision->reasonCode ?? 'MODULE_RESTRICTED',
                        (int) $run->id,
                    );
                }
                $result = FiscalAdapterResult::blocked(
                    $decision->reason ?? 'Operação fiscal indisponível.',
                    $decision->reasonCode ?? 'MODULE_UNAVAILABLE',
                );
            } else {
                // Franquia comercial: débito só no primeiro despacho remoto real.
                $commercialBlock = $this->authorizeCommercialBeforeRemote(
                    $office,
                    $client,
                    $run,
                    $module,
                );
                if ($commercialBlock !== null) {
                    $result = FiscalAdapterResult::blocked(
                        $commercialBlock['message'],
                        $commercialBlock['code'],
                    );
                } else {
                    $result = $adapter->execute($request);
                    if ($transientContext !== [] && $result->shouldRequeue) {
                        $result = FiscalAdapterResult::failed(
                            'A solicitação manual não pode ser repetida automaticamente; informe o dado novamente.',
                            'EPHEMERAL_CONTEXT_RETRY_FORBIDDEN',
                            $result->coverage,
                        );
                    }
                }
            }

            $persist = FiscalPersistPayload::fromAdapterResult($run, $result, $adapter::class);
            $out = $this->persistence->persist($persist);
            $fresh = $out['run'];
            if ($out['snapshot'] !== null && $out['evidence_id'] !== null) {
                $this->sitfisProjectionReconciler->reconcile($out['snapshot'], $persist->findings);
            }
            $this->pgdasdPostConsult->attachSnapshotToValidProjections($fresh, $out['snapshot']);
            if ($fresh->result !== FiscalRunResult::Success) {
                $this->pgdasdRbt12->reconcileTerminalFailure($fresh);
            } else {
                try {
                    $this->pgdasdPaymentReconciliation->enqueueAfterProductiveMonitor(
                        $office,
                        $client,
                        $fresh,
                    );
                } catch (\Throwable $exception) {
                    Log::warning('pgdasd.pagtoweb_reconciliation_enqueue_failed', [
                        'office_id' => $office->id,
                        'client_id' => $client->id,
                        'run_id' => $fresh->id,
                        'reason' => 'RECONCILIATION_ENQUEUE_FAILED',
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $latencyMs = null;
            if ($fresh->started_at !== null) {
                $latencyMs = (int) max(
                    0,
                    (CarbonImmutable::now()->getTimestampMs() - $fresh->started_at->getTimestampMs()),
                );
            }

            // Auditoria de consulta (sem payload fiscal / CNPJ / XML).
            $this->audit->record(
                action: 'fiscal.consulta.execute',
                result: $fresh->result?->value ?? $fresh->status->value,
                subject: $fresh,
                context: [
                    'system_code' => $fresh->system_code,
                    'service_code' => $fresh->service_code,
                    'operation_code' => $fresh->operation_code,
                    'status' => $fresh->status->value,
                    'result' => $fresh->result?->value,
                    'situation' => $fresh->situation?->value,
                    'coverage' => $fresh->coverage?->value,
                    'client_id' => $fresh->client_id,
                    'correlation_id' => $fresh->correlation_id,
                    'error_code' => $fresh->error_code,
                    'skip_reason' => $fresh->skip_reason,
                    'latency_ms' => $latencyMs,
                ],
                officeId: (int) $fresh->office_id,
            );

            $this->structuredLog->externalCall(
                channel: 'fiscal_monitoring',
                result: $fresh->result?->value ?? $fresh->status->value,
                latencyMs: $latencyMs,
                httpStatus: null,
                context: [
                    'service_code' => $fresh->service_code,
                    'operation_code' => $fresh->operation_code,
                    'module' => $adapter->moduleKey(),
                    'status' => $fresh->status->value,
                ],
                officeId: (int) $fresh->office_id,
            );

            $this->metrics->increment('fiscal.consulta.result', 1, [
                'result' => $fresh->result?->value ?? 'UNKNOWN',
                'service_code' => $fresh->service_code,
                'status' => $fresh->status->value,
            ]);

            if ($fresh->status === FiscalRunStatus::Requeued && $transientContext === []) {
                $delay = $result->requeueAfterSeconds;
                if ($delay === null || $delay < 0) {
                    $delay = (int) ($fresh->progress['requeue_after_seconds'] ?? 0);
                }
                $this->enqueueContinuation($fresh, max(0, (int) $delay));
            }

            if ($fresh->schedule_id && $fresh->result === FiscalRunResult::Success) {
                FiscalMonitoringSchedule::query()
                    ->withoutGlobalScopes()
                    ->whereKey($fresh->schedule_id)
                    ->update([
                        'last_success_at' => CarbonImmutable::now(),
                        'last_result' => FiscalRunResult::Success->value,
                    ]);
                $this->maybeQueueAutomaticCommunication(
                    $office,
                    $client,
                    $adapter->moduleKey(),
                    (string) $fresh->service_code,
                    $this->communicationPeriodKey($fresh),
                );
            }

            return $fresh;
        } finally {
            $this->scheduler->releaseSlots($slots);
            $lock->release();
        }
    }

    public function enqueueContinuation(FiscalMonitoringRun $parent, int $delaySeconds = 0): FiscalMonitoringRun
    {
        $attempt = $parent->attempt + 1;
        $slot = FiscalIdempotency::continuationSlot((int) $parent->id, $attempt);
        $key = FiscalIdempotency::runKey(
            (int) $parent->office_id,
            (int) $parent->client_id,
            $parent->system_code,
            $parent->service_code,
            $parent->operation_code,
            $parent->competence?->period_key,
            FiscalTrigger::Continuation,
            $slot,
        );

        $existing = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('office_id', $parent->office_id)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $child = FiscalMonitoringRun::query()->create([
            'office_id' => $parent->office_id,
            'client_id' => $parent->client_id,
            'fiscal_category_id' => $parent->fiscal_category_id,
            'competence_id' => $parent->competence_id,
            'schedule_id' => $parent->schedule_id,
            'last_update_event_id' => $parent->last_update_event_id,
            'system_code' => $parent->system_code,
            'service_code' => $parent->service_code,
            'operation_code' => $parent->operation_code,
            'operation_key' => $parent->operation_key,
            'source_provenance' => $parent->source_provenance,
            'verification_state' => $parent->verification_state,
            'trigger' => FiscalTrigger::Continuation,
            'idempotency_key' => $key,
            'status' => FiscalRunStatus::Queued,
            'situation' => FiscalSituation::Processing,
            'coverage' => $parent->coverage,
            'mutability' => $parent->mutability,
            'attempt' => $attempt,
            'parent_run_id' => $parent->id,
            'correlation_id' => $parent->correlation_id,
            'progress_cursor' => $parent->progress_cursor,
            'progress' => $parent->progress,
            'items_processed' => $parent->items_processed,
            'pages_processed' => $parent->pages_processed,
        ]);

        $pending = ExecuteFiscalMonitoringRunJob::dispatch($child->id)
            ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }

        return $child;
    }

    /**
     * @return array{code:string,message:string}|null
     */
    private function preflightBlockReason(Office $office, Client $client, FiscalMonitoringRun $run): ?array
    {
        if ((bool) config('fiscal.kill_switch', false) || (bool) config('fiscal_monitoring.kill_switch', false)) {
            return ['code' => 'KILL_SWITCH', 'message' => 'Kill switch ativo.'];
        }

        if (! $this->subscriptionGate->allowsExternalCalls($office)) {
            return ['code' => 'SUBSCRIPTION_BLOCKED', 'message' => 'Assinatura do escritório bloqueia chamadas externas.'];
        }

        if ((int) $client->office_id !== (int) $office->id) {
            return ['code' => 'CONTRIBUTOR_CROSS_TENANT', 'message' => 'Contribuinte de outro tenant.'];
        }

        if (! $office->is_active) {
            return ['code' => 'OFFICE_INACTIVE', 'message' => 'Escritório inativo.'];
        }

        return null;
    }

    /**
     * Consome franquia comercial imediatamente antes do transporte remoto.
     * NFS-e/SEFAZ/autXML e monitores fora do catálogo não debitam.
     * Retry/polling com o mesmo correlation_id reutiliza a entrada (sem reconsumo).
     *
     * @return array{code:string,message:string}|null
     */
    private function authorizeCommercialBeforeRemote(
        Office $office,
        Client $client,
        FiscalMonitoringRun $run,
        ?string $moduleKey,
    ): ?array {
        if (! (bool) config('fiscal_monitoring.commercial.enabled', false)) {
            return null;
        }

        if (CommercialMonitorCatalog::isRealtimeNonFranchiseChannel(
            $run->system_code,
            $run->service_code,
        )) {
            return null;
        }

        $progress = is_array($run->progress) ? $run->progress : [];
        $monitorKey = isset($progress['monitor_key']) && is_string($progress['monitor_key'])
            ? strtolower(trim($progress['monitor_key']))
            : CommercialMonitorCatalog::resolveMonitorKey(
                $run->system_code,
                $run->service_code,
                $moduleKey,
            );

        if ($monitorKey === null || ! CommercialMonitorCatalog::isCommercialMonitor($monitorKey)) {
            return null;
        }

        $origin = match (true) {
            isset($progress['commercial_origin'])
                && is_string($progress['commercial_origin'])
                && MonitorCommercialOrigin::tryFrom($progress['commercial_origin']) !== null => MonitorCommercialOrigin::from($progress['commercial_origin']),
            $run->trigger === FiscalTrigger::Scheduled => MonitorCommercialOrigin::Scheduled,
            default => MonitorCommercialOrigin::Manual,
        };

        $existingEntryId = isset($progress['commercial_ledger_entry_id'])
            ? (int) $progress['commercial_ledger_entry_id']
            : null;

        // Intervalo mínimo oficial: bloqueia despacho sem consumo (confirmação UI não contorna).
        if ((bool) config('fiscal_monitoring.commercial.enforce_min_interval', true)
            && $run->trigger === FiscalTrigger::Manual
            && $existingEntryId === null) {
            $intervalBlock = $this->commercialLedger->assertMinIntervalOrBlock(
                (int) $office->id,
                (int) $client->id,
                $monitorKey,
            );
            if ($intervalBlock !== null) {
                return [
                    'code' => $intervalBlock,
                    'message' => 'Intervalo mínimo oficial entre consultas deste monitor ainda não passou.',
                ];
            }
        }

        $idempotency = match ($origin) {
            MonitorCommercialOrigin::Scheduled => $this->commercialLedger->scheduledIdempotencyKey(
                (int) $office->id,
                (int) $client->id,
                $monitorKey,
                // period_key resolvido no serviço a partir da assinatura; slot estável do run.
                (string) ($progress['period_key'] ?? $run->correlation_id ?? $run->idempotency_key),
            ),
            MonitorCommercialOrigin::Inaugural => $this->commercialLedger->inauguralIdempotencyKey(
                (int) $office->id,
                (int) $client->id,
                $monitorKey,
            ),
            default => $this->commercialLedger->manualIdempotencyKey(
                (int) $office->id,
                (int) $client->id,
                $monitorKey,
                (string) ($progress['period_key'] ?? 'open'),
                (string) ($run->correlation_id ?? $run->idempotency_key),
            ),
        };

        // Preferir idempotency já materializada no ledger (scheduled/inaugural pending).
        if ($existingEntryId !== null) {
            $idempotency = (string) (
                MonitorCommercialLedgerEntry::query()
                    ->withoutGlobalScopes()
                    ->whereKey($existingEntryId)
                    ->value('idempotency_key')
                ?? $idempotency
            );
        }

        $outcome = $this->commercialLedger->authorizeAndDebitBeforeRemoteDispatch(
            officeId: (int) $office->id,
            clientId: (int) $client->id,
            monitorKey: $monitorKey,
            origin: $origin,
            idempotencyKey: $idempotency,
            technicalCorrelationId: $run->correlation_id,
            existingEntryId: $existingEntryId,
        );

        if (! $outcome['allowed']) {
            return [
                'code' => $outcome['block_reason'] ?? MonitorCommercialLedgerService::BLOCK_QUOTA,
                'message' => 'Franquia comercial do monitor esgotada para este período.',
            ];
        }

        if ($outcome['entry'] !== null) {
            $progress['commercial_ledger_entry_id'] = $outcome['entry']->id;
            $progress['monitor_key'] = $monitorKey;
            $progress['commercial_origin'] = $outcome['entry']->origin->value;
            $progress['commercial_debited'] = $outcome['debited'];
            $progress['commercial_inaugural'] = $outcome['inaugural'];
            $run->forceFill(['progress' => $progress])->save();
        }

        return null;
    }

    private function markBlocked(FiscalMonitoringRun $run, string $code, string $message): FiscalMonitoringRun
    {
        $payload = new FiscalPersistPayload(
            run: $run,
            result: FiscalRunResult::Blocked,
            situation: FiscalSituation::Blocked,
            coverage: $run->coverage ?? FiscalCoverage::Unknown,
            skipReason: $code,
            errorCode: $code,
            errorMessage: $message,
        );

        return $this->persistence->persist($payload)['run'];
    }

    private function markFailed(FiscalMonitoringRun $run, string $code, string $message): FiscalMonitoringRun
    {
        $payload = new FiscalPersistPayload(
            run: $run,
            result: FiscalRunResult::Failed,
            situation: FiscalSituation::Error,
            coverage: $run->coverage ?? FiscalCoverage::Unknown,
            errorCode: $code,
            errorMessage: $message,
        );

        return $this->persistence->persist($payload)['run'];
    }

    private function maybeQueueAutomaticCommunication(
        Office $office,
        Client $client,
        ?string $moduleKey,
        string $serviceCode = '',
        ?string $periodKey = null,
    ): void {
        $serviceCode = strtoupper($serviceCode);
        $service = match (true) {
            $moduleKey === 'simples_mei' && str_contains($serviceCode, 'PGMEI') => app(PgmeiCommunicationService::class),
            $moduleKey === 'simples_mei' && str_contains($serviceCode, 'PGDASD') => app(PgdasdCommunicationService::class),
            $moduleKey === 'dctfweb' && str_contains($serviceCode, 'MIT') => app(MitCommunicationService::class),
            $moduleKey === 'dctfweb' && str_contains($serviceCode, 'DCTFWEB') => app(DctfwebCommunicationService::class),
            $moduleKey === 'sitfis' => app(SitfisCommunicationService::class),
            $moduleKey === 'fgts' => app(FgtsCommunicationService::class),
            default => null,
        };
        if ($service === null) {
            return;
        }
        $service->maybeQueueAutomaticAfterConsult($office, $client, $periodKey);
    }

    private function communicationPeriodKey(FiscalMonitoringRun $run): ?string
    {
        $period = $run->competence_id
            ? FiscalCompetence::query()->withoutGlobalScopes()->whereKey($run->competence_id)->value('period_key')
            : null;
        if (! is_string($period) || ! preg_match('/^\d{4}-\d{2}$/', $period)) {
            $progress = is_array($run->progress) ? $run->progress : [];
            $period = $progress['period_key'] ?? $progress['competence_period_key'] ?? null;
        }

        return is_string($period) && preg_match('/^\d{4}-\d{2}$/', $period) ? $period : null;
    }
}
