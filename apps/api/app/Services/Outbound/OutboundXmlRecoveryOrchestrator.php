<?php

namespace App\Services\Outbound;

use App\Contracts\SvrsNfceOutboundXmlRetrievalClient;
use App\Contracts\SvrsNfe55OutboundXmlRetrievalClient;
use App\Contracts\SvrsPortalEgressGovernor;
use App\DTO\Outbound\SvrsEgressReservation;
use App\DTO\Outbound\SvrsNfceEligibilityResult;
use App\DTO\Outbound\SvrsNfceRetrievalRequest;
use App\Enums\DocumentAcquisitionSource;
use App\Enums\OutboundCaptureMode;
use App\Enums\OutboundFiscalModel;
use App\Enums\OutboundNumberStatus;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\OutboundRetrievalStatus;
use App\Enums\OutboundUrgencyBand;
use App\Enums\SvrsEgressBlockCause;
use App\Enums\SvrsNfceFailureReason;
use App\Enums\SvrsNfceRecoveryStatus;
use App\Enums\SvrsNfceTransportOutcome;
use App\Jobs\RecoverSvrsNfceXmlJob;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\MaOutboundRetrievalRequest;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundNumberState;
use App\Models\OutboundXmlRecoveryAttempt;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\CredentialService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orquestração idempotente KEY_DISCOVERED/XML_PENDING → recuperação SVRS (65 e 55).
 */
final class OutboundXmlRecoveryOrchestrator
{
    public function __construct(
        private readonly SvrsNfceConfig $config,
        private readonly SvrsNfe55Config $nfe55Config,
        private readonly SvrsNfceRetrievalEligibility $eligibility,
        private readonly SvrsNfe55RetrievalEligibility $nfe55Eligibility,
        private readonly SvrsNfceKillSwitchService $killSwitch,
        private readonly SvrsNfe55KillSwitchService $nfe55KillSwitch,
        private readonly SvrsNfceRateLimiter $rateLimiter,
        private readonly SvrsNfceCircuitBreaker $breaker,
        private readonly SvrsPortalEgressGovernor $egressGovernor,
        private readonly SvrsNfceOutboundXmlRetrievalClient $client,
        private readonly SvrsNfe55OutboundXmlRetrievalClient $nfe55Client,
        private readonly SvrsNfceXmlIngestionService $ingestion,
        private readonly CredentialService $credentials,
        private readonly AuditLogger $audit,
        private readonly OutboundMetrics $metrics,
    ) {}

    /**
     * Cria ou reutiliza recovery ativa e opcionalmente enfileira.
     */
    public function ensureRecovery(
        OutboundNumberState $number,
        OutboundCaptureProfile $profile,
        bool $queue = true,
        ?int $userId = null,
        string $triggeredBy = 'system',
    ): ?MaOutboundRetrievalRequest {
        if ((int) $number->office_id !== (int) $profile->office_id
            || (int) $number->outbound_capture_profile_id !== (int) $profile->id) {
            return null;
        }

        $a1Ok = $this->hasA1((int) $profile->client_id);
        $eval = $this->evaluateEligibility($number, $profile, $a1Ok);
        if (! $eval->eligible) {
            return null;
        }

        $key = $this->normalizeAccessKey(
            (string) ($number->discovered_access_key ?: $number->candidate_access_key)
        );
        if ($key === null) {
            return null;
        }

        if ($number->status === OutboundNumberStatus::KeyDiscovered) {
            $number->forceFill(['status' => OutboundNumberStatus::XmlPending])->save();
        }

        try {
            $request = DB::transaction(function () use ($number, $profile, $key, $userId) {
                $existing = MaOutboundRetrievalRequest::query()
                    ->where('office_id', $profile->office_id)
                    ->where('outbound_capture_profile_id', $profile->id)
                    ->where('access_key', $key)
                    ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
                    ->whereNotIn('recovery_status', [
                        SvrsNfceRecoveryStatus::Captured->value,
                        SvrsNfceRecoveryStatus::NotAvailableVisible->value,
                        SvrsNfceRecoveryStatus::Blocked->value,
                        SvrsNfceRecoveryStatus::ResolvedByOtherSource->value,
                    ])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                return MaOutboundRetrievalRequest::query()->create([
                    'office_id' => $profile->office_id,
                    'outbound_capture_profile_id' => $profile->id,
                    'establishment_id' => $profile->establishment_id,
                    'environment' => $profile->environment,
                    'model' => $profile->model,
                    'direction' => 'OUT',
                    'competence' => now()->format('Y-m'),
                    'status' => OutboundRetrievalStatus::Pending,
                    'mode' => OutboundCaptureMode::Automatic,
                    'origin' => OutboundRetrievalOrigin::SvrsPortalByKey,
                    'access_key' => $key,
                    'outbound_number_state_id' => $number->id,
                    'recovery_status' => SvrsNfceRecoveryStatus::Eligible,
                    'attempt_count' => 0,
                    'correlation_id' => (string) Str::uuid(),
                    'created_by' => $userId,
                ]);
            });
        } catch (Throwable) {
            // Unique parcial (PG) ou corrida: re-ler recovery ativa
            $request = MaOutboundRetrievalRequest::query()
                ->where('office_id', $profile->office_id)
                ->where('outbound_capture_profile_id', $profile->id)
                ->where('access_key', $key)
                ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
                ->whereNotIn('recovery_status', [
                    SvrsNfceRecoveryStatus::Captured->value,
                    SvrsNfceRecoveryStatus::NotAvailableVisible->value,
                    SvrsNfceRecoveryStatus::Blocked->value,
                    SvrsNfceRecoveryStatus::ResolvedByOtherSource->value,
                ])
                ->first();
            if ($request === null) {
                return null;
            }
        }

        if ($queue) {
            $this->enqueue($request, $userId, $triggeredBy);
        }

        return $request->fresh();
    }

    /**
     * Enfileira somente se ainda não há job em andamento (QUEUED/RUNNING).
     */
    public function enqueue(MaOutboundRetrievalRequest $request, ?int $userId = null, string $triggeredBy = 'system'): bool
    {
        if ($request->origin !== OutboundRetrievalOrigin::SvrsPortalByKey) {
            return false;
        }
        if ($request->recovery_status?->isTerminal()) {
            return false;
        }

        // Não re-dispatch se já está na fila ou em execução
        if (in_array($request->recovery_status, [
            SvrsNfceRecoveryStatus::Queued,
            SvrsNfceRecoveryStatus::Running,
        ], true)) {
            return false;
        }

        // RETRY_SCHEDULED só se next_attempt_at vencido (ou null)
        if ($request->recovery_status === SvrsNfceRecoveryStatus::RetryScheduled
            && $request->next_attempt_at !== null
            && $request->next_attempt_at->isFuture()) {
            return false;
        }

        $request->forceFill([
            'recovery_status' => SvrsNfceRecoveryStatus::Queued,
            'status' => OutboundRetrievalStatus::Requested,
            'requested_at' => $request->requested_at ?? now(),
        ])->save();

        $profileForQueue = OutboundCaptureProfile::withoutGlobalScopes()->find($request->outbound_capture_profile_id);
        $queue = ($profileForQueue !== null && $this->profileModelCode($profileForQueue) === '55')
            ? $this->nfe55Config->queue()
            : $this->config->queue();

        RecoverSvrsNfceXmlJob::dispatch($request->id)
            ->onQueue($queue);

        $this->audit->record('svrs_nfce.recovery.queued', 'SUCCESS', $request, [
            'triggered_by' => $triggeredBy,
            'correlation_id' => $request->correlation_id,
        ], $userId, $request->office_id);

        $this->metrics->increment('svrs_nfce_queued');

        return true;
    }

    /**
     * Executa uma tentativa (chamado pelo job). Payload do job só tem id interno.
     */
    public function runAttempt(int $requestId): void
    {
        $ttl = max(30, $this->config->lockTtlSeconds());
        $lock = Cache::lock('svrs_nfce.recovery.'.$requestId, $ttl);
        if (! $lock->get()) {
            return;
        }

        $reservation = null;

        try {
            $this->runAttemptLocked($requestId, $reservation);
        } finally {
            if ($reservation !== null) {
                $this->rateLimiter->release($reservation);
            }
            $lock->release();
        }
    }

    private function runAttemptLocked(int $requestId, ?SvrsEgressReservation &$reservation): void
    {
        $request = MaOutboundRetrievalRequest::withoutGlobalScopes()->find($requestId);
        if ($request === null) {
            return;
        }

        if ($request->origin !== OutboundRetrievalOrigin::SvrsPortalByKey) {
            return;
        }

        if ($request->recovery_status === SvrsNfceRecoveryStatus::Captured
            || $request->recovery_status === SvrsNfceRecoveryStatus::ResolvedByOtherSource) {
            return;
        }

        // Já RUNNING por outro worker (não deveria com lock, mas defensivo)
        if ($request->recovery_status === SvrsNfceRecoveryStatus::Running
            && $request->updated_at !== null
            && $request->updated_at->gt(now()->subSeconds($this->config->lockTtlSeconds()))) {
            return;
        }

        $profile = OutboundCaptureProfile::withoutGlobalScopes()->find($request->outbound_capture_profile_id);
        $number = OutboundNumberState::withoutGlobalScopes()->find($request->outbound_number_state_id);
        if ($profile === null || $number === null) {
            return;
        }

        if ((int) $request->office_id !== (int) $profile->office_id
            || (int) $number->office_id !== (int) $profile->office_id) {
            return;
        }

        if (in_array($number->status, [OutboundNumberStatus::XmlCaptured, OutboundNumberStatus::Complete], true)
            && $number->dfe_document_id) {
            $request->forceFill([
                'recovery_status' => SvrsNfceRecoveryStatus::ResolvedByOtherSource,
                'failure_reason' => SvrsNfceFailureReason::CapturedByOther,
            ])->save();

            return;
        }

        $modelCode = $this->profileModelCode($profile);
        $channelEnabled = $modelCode === '55'
            ? $this->nfe55Config->retrievalEnabled()
            : $this->config->retrievalEnabled();
        $killActive = $modelCode === '55'
            ? $this->nfe55KillSwitch->isActive()
            : $this->killSwitch->isActive();

        if (! $channelEnabled || $killActive) {
            $this->scheduleRetry(
                $request,
                $killActive ? SvrsNfceFailureReason::KillSwitch : SvrsNfceFailureReason::ChannelDisabled,
                'Canal off ou kill switch — fallback assistido.',
                900,
            );

            return;
        }

        $globalState = $this->breaker->globalStatus()['state'];
        $rootState = $this->breaker->rootStatus((int) $profile->client_id)['state'];
        $cohortState = $this->egressGovernor->cohortHealth()['state'] ?? 'open';
        $isProbe = $globalState === 'half_open'
            || $rootState === 'half_open'
            || $cohortState === 'half_open';

        // Probe half-open: qualquer recovery elegível (não exige allowlist de piloto)
        if (! $this->breaker->isCallAllowed((int) $profile->client_id, $isProbe)) {
            $this->scheduleRetry($request, SvrsNfceFailureReason::BreakerOpen, 'Circuit breaker aberto.', 900);

            return;
        }

        // Reserva no governador compartilhado ANTES de materializar A1
        if (! $this->egressGovernor->isCallAllowed($isProbe)) {
            $this->scheduleRetry($request, SvrsNfceFailureReason::BreakerOpen, 'Circuit breaker coorte aberto.', 900);

            return;
        }

        $establishmentForRoot = $profile->establishment()->withoutGlobalScopes()->first()
            ?? Establishment::withoutGlobalScopes()->find($profile->establishment_id);
        $rootCnpj = $establishmentForRoot?->cnpj
            ?? Client::withoutGlobalScopes()->find($profile->client_id)?->root_cnpj
            ?? ('CLIENT'.$profile->client_id);

        $rate = $this->rateLimiter->acquire(
            (int) $profile->client_id,
            (string) $rootCnpj,
            (int) $profile->office_id,
            $modelCode === '55' ? 'nfe55' : 'nfce65',
            $isProbe,
        );
        if (! $rate['allowed'] || ($rate['reservation'] ?? null) === null) {
            $this->scheduleRetry(
                $request,
                SvrsNfceFailureReason::RateLimited,
                'Orçamento de egress SVRS esgotado.',
                max(1, $rate['retry_after_seconds']),
            );

            return;
        }
        $reservation = $rate['reservation'];

        $correlationId = $request->correlation_id ?: (string) Str::uuid();
        $attemptNumber = (int) $request->attempt_count + 1;
        $startedAt = now();

        $request->forceFill([
            'recovery_status' => SvrsNfceRecoveryStatus::Running,
            'status' => OutboundRetrievalStatus::Processing,
            'correlation_id' => $correlationId,
            'attempt_count' => $attemptNumber,
            // Contabiliza transação externa efetiva (GET+POST) mesmo se a resposta falhar depois
            'svrs_transaction_count' => (int) $request->svrs_transaction_count + 1,
            'dispatched_at' => now(),
        ])->save();

        $certificate = null;
        $resultOutcome = null;
        $failure = null;
        $detail = null;
        $sha = null;
        $getMs = null;
        $postMs = null;
        $totalMs = null;
        $httpStatus = null;
        $parserVersion = null;
        $retryAfterHint = null;

        try {
            $certificate = $this->materializeA1((int) $profile->client_id);
            if ($certificate === null) {
                $failure = SvrsNfceFailureReason::A1Unavailable;
                $detail = 'A1 indisponível.';
                $resultOutcome = SvrsNfceTransportOutcome::AuthForbidden;
            } else {
                $dto = new SvrsNfceRetrievalRequest(
                    accessKey: (string) $request->access_key,
                    environment: (string) $profile->environment,
                    correlationId: $correlationId,
                    officeId: (int) $profile->office_id,
                    profileId: (int) $profile->id,
                    clientId: (int) $profile->client_id,
                    establishmentId: (int) $profile->establishment_id,
                );

                $result = $modelCode === '55'
                    ? $this->nfe55Client->retrieve($dto, $certificate)
                    : $this->client->retrieve($dto, $certificate);
                $resultOutcome = $result->outcome;
                $getMs = $result->getLatencyMs;
                $postMs = $result->postLatencyMs;
                $totalMs = $result->totalLatencyMs;
                $httpStatus = $result->httpStatus;
                $parserVersion = $result->parserVersion;
                $detail = $result->sanitizedDetail;
                $retryAfterHint = $result->retryAfterSeconds;

                if ($result->isSuccess()) {
                    $establishment = $profile->establishment()->withoutGlobalScopes()->first()
                        ?? Establishment::withoutGlobalScopes()->find($profile->establishment_id);

                    if ($establishment === null) {
                        $failure = SvrsNfceFailureReason::NotEligible;
                        $detail = 'Estabelecimento ausente.';
                    } else {
                        $source = $modelCode === '55'
                            ? DocumentAcquisitionSource::SvrsNfe55DownloadXmlDfe
                            : DocumentAcquisitionSource::SvrsNfceDownloadXmlDfe;
                        $ingest = $this->ingestion->ingestValidatedBytes(
                            $profile,
                            $establishment,
                            $number,
                            $request,
                            $result->xmlBytes ?? '',
                            (string) $request->access_key,
                            $correlationId,
                            $source,
                            $modelCode,
                        );

                        $sha = $ingest['sha256'] ?? $result->sha256;

                        if (($ingest['status'] ?? '') === 'captured' || ($ingest['status'] ?? '') === 'duplicate') {
                            $this->breaker->recordSuccess((int) $profile->client_id);
                            if ($isProbe) {
                                $this->egressGovernor->closeBreakerAfterCanarySuccess(officeId: (int) $profile->office_id);
                            }
                            $this->metrics->increment(
                                ($ingest['status'] ?? '') === 'duplicate' ? 'svrs_nfce_duplicate' : 'svrs_nfce_captured'
                            );
                            $this->recordAttempt(
                                $request, $profile, $number, $correlationId, $attemptNumber,
                                SvrsNfceRecoveryStatus::Captured, null, $resultOutcome,
                                $httpStatus, $parserVersion, $getMs, $postMs, $totalMs, $detail, $sha, $startedAt
                            );

                            return;
                        }

                        $failure = $ingest['failure_reason'] ?? SvrsNfceFailureReason::InvalidXml;
                        $detail = $ingest['sanitized_detail'] ?? $detail;
                    }
                } else {
                    $failure = $result->outcome->toFailureReason() ?? SvrsNfceFailureReason::HttpTransient;
                }
            }
        } catch (Throwable $e) {
            $failure = SvrsNfceFailureReason::HttpTransient;
            $detail = 'Falha interna sanitizada.';
            // não re-lançar: nunca deixar RUNNING órfão
        } finally {
            if (is_array($certificate)) {
                $certificate['pfx'] = '';
                $certificate['password'] = '';
            }
            unset($certificate);
        }

        if ($failure === null) {
            $failure = SvrsNfceFailureReason::HttpTransient;
        }

        if ($failure === SvrsNfceFailureReason::EgressBlockedMultipleQueries
            || $resultOutcome === SvrsNfceTransportOutcome::EgressBlockedMultipleQueries) {
            $this->egressGovernor->openBreaker(
                SvrsEgressBlockCause::MultipleQueries,
                templateFingerprint: 'multiple_queries_block_v1',
                retryAfterSeconds: $retryAfterHint,
                officeId: (int) $profile->office_id,
            );
        } elseif ($failure === SvrsNfceFailureReason::ResponseContractChanged) {
            $this->egressGovernor->openBreaker(
                SvrsEgressBlockCause::ContractChanged,
                officeId: (int) $profile->office_id,
            );
        }

        $this->breaker->recordFailure($failure, (int) $profile->client_id, null, (int) $profile->office_id);

        $terminalStatus = $this->resolveTerminalStatus($failure, $attemptNumber);
        if ($terminalStatus === SvrsNfceRecoveryStatus::NotAvailableVisible) {
            $failure = SvrsNfceFailureReason::MaxAttempts;
        }

        $this->recordAttempt(
            $request, $profile, $number, $correlationId, $attemptNumber,
            $terminalStatus, $failure, $resultOutcome,
            $httpStatus, $parserVersion, $getMs, $postMs, $totalMs, $detail, $sha, $startedAt
        );

        if ($terminalStatus === SvrsNfceRecoveryStatus::RetryScheduled) {
            $delay = $this->backoffSeconds($attemptNumber);
            if ($retryAfterHint !== null && $retryAfterHint > 0) {
                $delay = max($delay, $retryAfterHint);
            }
            $request->forceFill([
                'recovery_status' => SvrsNfceRecoveryStatus::RetryScheduled,
                'failure_reason' => $failure,
                'last_error' => mb_substr((string) $detail, 0, 500),
                'next_attempt_at' => now()->addSeconds($delay),
            ])->save();

            $this->metrics->increment('svrs_nfce_retry');
            $profileForQueue = OutboundCaptureProfile::withoutGlobalScopes()->find($request->outbound_capture_profile_id);
            $queue = ($profileForQueue !== null && $this->profileModelCode($profileForQueue) === '55')
                ? $this->nfe55Config->queue()
                : $this->config->queue();
            RecoverSvrsNfceXmlJob::dispatch($request->id)
                ->delay(now()->addSeconds($delay))
                ->onQueue($queue);

            if ($number->status !== OutboundNumberStatus::XmlCaptured) {
                $number->forceFill(['status' => OutboundNumberStatus::XmlPending])->save();
            }

            return;
        }

        $request->forceFill([
            'recovery_status' => $terminalStatus,
            'failure_reason' => $failure,
            'last_error' => mb_substr((string) $detail, 0, 500),
            'next_attempt_at' => null,
        ])->save();

        if ($number->status !== OutboundNumberStatus::XmlCaptured) {
            $number->forceFill([
                'status' => OutboundNumberStatus::XmlPending,
                'block_reason' => $terminalStatus === SvrsNfceRecoveryStatus::Blocked
                    ? mb_substr((string) $detail, 0, 500)
                    : $number->block_reason,
            ])->save();
        }

        $this->metrics->increment(
            $terminalStatus === SvrsNfceRecoveryStatus::Blocked ? 'svrs_nfce_blocked' : 'svrs_nfce_fallback'
        );
    }

    public function resolveByOtherSource(int $officeId, string $accessKey, string $sourceLabel = 'other'): void
    {
        $key = $this->normalizeAccessKey($accessKey);
        if ($key === null) {
            return;
        }

        MaOutboundRetrievalRequest::withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('access_key', $key)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereNotIn('recovery_status', [
                SvrsNfceRecoveryStatus::Captured->value,
                SvrsNfceRecoveryStatus::ResolvedByOtherSource->value,
            ])
            ->update([
                'recovery_status' => SvrsNfceRecoveryStatus::ResolvedByOtherSource->value,
                'failure_reason' => SvrsNfceFailureReason::CapturedByOther->value,
                'last_error' => 'Resolvido por '.$sourceLabel,
                'next_attempt_at' => null,
                'urgency_band' => OutboundUrgencyBand::Captured->value,
                'capture_source' => mb_substr($sourceLabel, 0, 40),
                'captured_at' => now(),
            ]);
    }

    private function evaluateEligibility(
        OutboundNumberState $number,
        OutboundCaptureProfile $profile,
        bool $a1Ok,
    ): SvrsNfceEligibilityResult {
        return $this->profileModelCode($profile) === '55'
            ? $this->nfe55Eligibility->evaluate($number, $profile, $a1Ok)
            : $this->eligibility->evaluate($number, $profile, $a1Ok);
    }

    private function profileModelCode(OutboundCaptureProfile $profile): string
    {
        $model = $profile->model instanceof OutboundFiscalModel
            ? $profile->model
            : OutboundFiscalModel::tryFrom((string) $profile->model);

        return $model === OutboundFiscalModel::Nfe ? '55' : '65';
    }

    private function normalizeAccessKey(string $raw): ?string
    {
        return $this->eligibility->normalizeKey($raw)
            ?? $this->nfe55Eligibility->normalizeKey($raw);
    }

    private function scheduleRetry(
        MaOutboundRetrievalRequest $request,
        SvrsNfceFailureReason $reason,
        string $detail,
        int $delaySeconds,
    ): void {
        // Jitter determinístico (sem retry na mesma execução — job tries=1)
        $ratio = (float) config('sefaz.svrs_portal_egress.retry_jitter_ratio', 0.1);
        $jitter = (int) floor($delaySeconds * $ratio * (($request->id % 10) / 10));
        $delaySeconds = max(1, $delaySeconds + $jitter);

        $request->forceFill([
            'recovery_status' => SvrsNfceRecoveryStatus::RetryScheduled,
            'failure_reason' => $reason,
            'last_error' => mb_substr($detail, 0, 500),
            'next_attempt_at' => now()->addSeconds($delaySeconds),
        ])->save();

        $profile = OutboundCaptureProfile::withoutGlobalScopes()->find($request->outbound_capture_profile_id);
        $queue = ($profile !== null && $this->profileModelCode($profile) === '55')
            ? $this->nfe55Config->queue()
            : $this->config->queue();

        RecoverSvrsNfceXmlJob::dispatch($request->id)
            ->delay(now()->addSeconds($delaySeconds))
            ->onQueue($queue);
    }

    private function resolveTerminalStatus(SvrsNfceFailureReason $failure, int $attemptNumber): SvrsNfceRecoveryStatus
    {
        if (! $failure->isRecoverable()) {
            return SvrsNfceRecoveryStatus::Blocked;
        }
        $max = $this->maxRecoverableAttempts();
        if ($attemptNumber >= $max) {
            // Com política de prazo: esgota tentativas → contingência (não marca capturado)
            return SvrsNfceRecoveryStatus::NotAvailableVisible;
        }

        return SvrsNfceRecoveryStatus::RetryScheduled;
    }

    private function maxRecoverableAttempts(): int
    {
        if (config('outbound_deadline.deadline_retry_policy')) {
            return max(1, min(2, (int) config('outbound_deadline.max_svrs_transactions_per_key', 2)));
        }

        return $this->config->maxRecoverableAttempts();
    }

    private function backoffSeconds(int $attemptNumber): int
    {
        // Política orientada ao prazo: mínimo 24h entre tentativas (sem 15m/1h/6h/12h).
        if (config('outbound_deadline.deadline_retry_policy')) {
            $hours = max(1, (int) config('outbound_deadline.min_hours_between_svrs_attempts', 24));

            return $hours * 3600;
        }

        $schedule = $this->config->retryBackoffSeconds();
        $idx = min(max(0, $attemptNumber - 1), count($schedule) - 1);
        $base = $schedule[$idx];
        $jitter = $this->config->retryJitterRatio();
        $delta = (int) round($base * $jitter * (mt_rand(-1000, 1000) / 1000));

        return max(1, $base + $delta);
    }

    private function recordAttempt(
        MaOutboundRetrievalRequest $request,
        OutboundCaptureProfile $profile,
        OutboundNumberState $number,
        string $correlationId,
        int $attemptNumber,
        SvrsNfceRecoveryStatus $result,
        ?SvrsNfceFailureReason $failure,
        ?SvrsNfceTransportOutcome $outcome,
        ?int $httpStatus,
        ?string $parserVersion,
        ?int $getMs,
        ?int $postMs,
        ?int $totalMs,
        ?string $detail,
        ?string $sha,
        $startedAt,
    ): void {
        $this->metrics->increment('svrs_egress_results', 1, [
            'model' => $this->profileModelCode($profile),
            'outcome' => $failure?->value ?? $result->value,
        ]);

        try {
            OutboundXmlRecoveryAttempt::query()->create([
                'office_id' => $request->office_id,
                'ma_outbound_retrieval_request_id' => $request->id,
                'outbound_capture_profile_id' => $profile->id,
                'outbound_number_state_id' => $number->id,
                'access_key' => $request->access_key,
                'correlation_id' => $correlationId,
                'attempt_number' => $attemptNumber,
                'result' => $result,
                'failure_reason' => $failure,
                'transport_outcome' => $outcome,
                'http_status' => $httpStatus,
                'parser_version' => $parserVersion,
                'get_latency_ms' => $getMs,
                'post_latency_ms' => $postMs,
                'total_latency_ms' => $totalMs,
                'sanitized_detail' => $detail ? mb_substr($detail, 0, 500) : null,
                'sha256' => $sha,
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);
        } catch (Throwable) {
            // unique attempt_number sob corrida residual — não derruba o fluxo
        }
    }

    private function hasA1(int $clientId): bool
    {
        $client = Client::withoutGlobalScopes()->find($clientId);
        if ($client === null) {
            return false;
        }

        return $this->credentials->activeFor($client) !== null;
    }

    /**
     * @return array{pfx: string, password: string}|null
     */
    private function materializeA1(int $clientId): ?array
    {
        $client = Client::withoutGlobalScopes()->find($clientId);
        if ($client === null) {
            return null;
        }

        $credential = $this->credentials->activeFor($client);
        if ($credential === null) {
            return null;
        }

        try {
            $material = $this->credentials->loadPfxMaterial($credential);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($material) || ($material['pfx'] ?? '') === '') {
            return null;
        }

        return [
            'pfx' => $material['pfx'],
            'password' => (string) ($material['password'] ?? ''),
        ];
    }
}
