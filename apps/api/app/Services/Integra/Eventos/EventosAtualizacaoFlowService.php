<?php

namespace App\Services\Integra\Eventos;

use App\Contracts\SerproOperationExecutor;
use App\DTO\Serpro\EventosBatchContributor;
use App\DTO\Serpro\IntegraResponse;
use App\DTO\Serpro\SerproOperationCommand;
use App\Enums\SerproCapabilityDriver;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Office;
use App\Models\SerproEventosRun;
use App\Services\Integra\Mailbox\MailboxEventosResultProcessor;
use App\Services\Serpro\CapabilityDriverResolver;
use App\Services\Serpro\EventosRateLimiter;
use App\Services\Serpro\SerproJobFlagGuard;
use App\Support\LogSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fluxo Eventos de Atualização: solicitar → aguardar → obter (one-shot).
 *
 * Persiste protocolo, TempoEsperaMedioEmMs e TempoLimiteEmMin da resposta.
 * Polling usa valores recebidos — sem TTL hardcoded no lugar do limite oficial.
 * Após obter 200, resultado é consumido e persistido atomicamente (não re-lê).
 */
final class EventosAtualizacaoFlowService
{
    public function __construct(
        private readonly SerproOperationExecutor $operations,
        private readonly EventosRateLimiter $limits,
        private readonly CapabilityDriverResolver $drivers,
        private readonly SerproJobFlagGuard $flags,
        private readonly EventosPjCodec $pjCodec,
        private readonly EventosResultArtifactStore $artifacts,
        private readonly MailboxEventosResultProcessor $processor,
    ) {}

    /**
     * Inicia solicitação assíncrona de eventos.
     *
     * @param  list<string>|null  $contributorIdentities  identidades no lote (sem PII em log)
     */
    public function solicit(
        Office $office,
        string $personType,
        string $evento,
        ?Client $client = null,
        ?array $contributorIdentities = null,
        ?SerproEnvironment $environment = null,
        ?string $correlationId = null,
    ): SerproEventosRun {
        $personType = strtoupper($personType);
        if (! in_array($personType, ['PF', 'PJ'], true)) {
            throw new RuntimeException('EVENTOS_PERSON_TYPE_INVALID: use PF ou PJ.');
        }

        $flag = $this->flags->assertAllowed('PollEventosAtualizacaoJob', $office->id);
        if (! $flag['allowed']) {
            throw new RuntimeException(($flag['code'] ?? 'FLAG_BLOCKED').': '.($flag['message'] ?? ''));
        }

        $driver = $this->drivers->forCapability('authorization');
        if ($driver === SerproCapabilityDriver::Disabled) {
            throw new RuntimeException('CAPABILITY_DISABLED: authorization/eventos desabilitado.');
        }

        $batch = EventosBatchContributor::forSolicit($personType, $contributorIdentities ?? []);
        $contributorsCount = count($batch->numbers);
        $this->limits->assertBatchSize($contributorsCount);

        $this->limits->attemptDaily((int) $office->id, $personType);

        $environment ??= SerproEnvironment::tryFrom(strtoupper((string) config('serpro.default_environment', 'TRIAL')))
            ?? SerproEnvironment::Trial;
        $correlationId ??= (string) Str::uuid();

        $solicitKey = $personType === 'PF'
            ? (string) config('serpro.eventos.solicit_pf_operation_key')
            : (string) config('serpro.eventos.solicit_pj_operation_key');
        $obterKey = $personType === 'PF'
            ? (string) config('serpro.eventos.obter_pf_operation_key')
            : (string) config('serpro.eventos.obter_pj_operation_key');

        $businessData = $personType === 'PJ'
            ? $this->pjCodec->solicit($evento)
            : ['evento' => $evento];

        $response = $this->operations->run(new SerproOperationCommand(
            office: $office,
            client: $client,
            operationKey: $solicitKey,
            businessData: $businessData,
            idempotencyKey: sprintf('eventos:solicit:%d:%s:%s', $office->id, $personType, $correlationId),
            correlationId: $correlationId,
            module: 'authorization',
            eventosBatchContributor: $batch,
        ));

        if ($response->hasSimulatedSource()) {
            return $this->persistFailedSolicit(
                $office, $client, $environment, $personType, $evento, $correlationId,
                $solicitKey, $obterKey, max(1, $contributorsCount),
                'SIMULATED_SOURCE_REJECTED',
                'Resposta sintética não pode iniciar fluxo de eventos.',
                false,
            );
        }

        if ($response->httpStatus === 429 || $response->errorCode === 'RATE_LIMIT_LOCAL') {
            $until = $this->limits->markRemote429(
                (int) $office->id,
                $personType,
                $response->retryAfterSeconds,
            );

            return $this->persistFailedSolicit(
                $office,
                $client,
                $environment,
                $personType,
                $evento,
                $correlationId,
                $solicitKey,
                $obterKey,
                max(1, $contributorsCount),
                'RATE_LIMIT_EVENTOS',
                '429 — sem retry até '.$until->toIso8601String(),
                $response->simulated,
            );
        }

        if (! $response->success) {
            return $this->persistFailedSolicit(
                $office,
                $client,
                $environment,
                $personType,
                $evento,
                $correlationId,
                $solicitKey,
                $obterKey,
                max(1, $contributorsCount),
                $response->errorCode ?? 'EVENTOS_SOLICIT_FAILED',
                $response->errorMessage ?? 'Falha ao solicitar eventos.',
                $response->simulated,
            );
        }

        $parsed = $this->parseSolicitPayload($response);
        if ($parsed['protocol'] === null || $parsed['protocol'] === '') {
            throw new RuntimeException('EVENTOS_PROTOCOL_MISSING: solicitação sem protocolo.');
        }

        $now = CarbonImmutable::now();
        $waitMs = $parsed['tempo_espera_medio_ms'];
        if ($waitMs === null || $waitMs < 0) {
            // Fallback defensivo só se omitido — NÃO substitui TempoLimiteEmMin
            $waitMs = max(0, (int) config('serpro.eventos.fallback_wait_ms_if_omitted', 5000));
        }
        $limitMin = $parsed['tempo_limite_em_min'];
        // Sem hardcoded de TTL de protocolo: se omitido, fail-closed (exige valor oficial)
        if ($limitMin === null || $limitMin <= 0) {
            throw new RuntimeException(
                'EVENTOS_TTL_MISSING: TempoLimiteEmMin ausente na resposta — não usar TTL hardcoded.'
            );
        }

        $notBefore = $now->addMilliseconds($waitMs);
        $expiresAt = $now->addMinutes($limitMin);

        return SerproEventosRun::query()->create([
            'office_id' => $office->id,
            'client_id' => $client?->id,
            'environment' => $environment,
            'person_type' => $personType,
            'phase' => SerproEventosRun::PHASE_WAITING,
            'protocol' => $parsed['protocol'],
            'tempo_espera_medio_ms' => $waitMs,
            'tempo_limite_em_min' => $limitMin,
            'not_before_at' => $notBefore,
            'expires_at' => $expiresAt,
            'result_consumed' => false,
            'one_shot_complete' => false,
            'status' => SerproEventosRun::STATUS_RUNNING,
            'correlation_id' => $correlationId,
            'operation_key_solicit' => $solicitKey,
            'operation_key_obter' => $obterKey,
            'evento' => $evento,
            'contributors_in_batch' => max(1, $contributorsCount),
            'simulated' => $response->simulated,
            'progress' => [
                'phase' => SerproEventosRun::PHASE_WAITING,
                'wait_ms_source' => $parsed['tempo_espera_medio_ms'] !== null ? 'response' : 'fallback_omitted',
                'limit_min_source' => 'response',
            ],
            'solicited_at' => $now,
        ]);
    }

    /**
     * Aguarda (se necessário) e obtém resultado one-shot.
     * Se já consumido, retorna o run persistido sem nova chamada.
     */
    public function obtain(SerproEventosRun $run): SerproEventosRun
    {
        if ($run->isOneShotConsumed()) {
            return $run->local_processing_status === MailboxEventosResultProcessor::LOCAL_SUCCEEDED
                ? $run
                : $this->processor->process($run);
        }

        if ($run->protocol === null || $run->protocol === '') {
            throw new RuntimeException('EVENTOS_PROTOCOL_MISSING');
        }
        if ($run->evento === null || trim($run->evento) === '') {
            throw new RuntimeException('EVENTOS_EVENT_MISSING');
        }

        $now = CarbonImmutable::now();
        if ($run->expires_at !== null && $now->greaterThan($run->expires_at)) {
            $run->forceFill([
                'phase' => SerproEventosRun::PHASE_EXPIRED,
                'status' => SerproEventosRun::STATUS_FAILED,
                'error_code' => 'EVENTOS_PROTOCOL_EXPIRED',
                'error_message' => 'Protocolo expirou conforme TempoLimiteEmMin da resposta.',
                'finished_at' => null,
            ])->save();

            return $run->fresh() ?? $run;
        }

        if ($run->not_before_at !== null && $now->lessThan($run->not_before_at)) {
            // Ainda na janela de espera oficial — sem chamada
            $run->forceFill([
                'phase' => SerproEventosRun::PHASE_WAITING,
                'progress' => array_merge($run->progress ?? [], [
                    'seconds_until_obtain' => (int) $now->diffInSeconds($run->not_before_at, false),
                ]),
            ])->save();

            return $run->fresh() ?? $run;
        }

        $flag = $this->flags->assertAllowed('PollEventosAtualizacaoJob', (int) $run->office_id);
        if (! $flag['allowed']) {
            $run->forceFill([
                'status' => SerproEventosRun::STATUS_BLOCKED,
                'error_code' => $flag['code'],
                'error_message' => mb_substr((string) $flag['message'], 0, 500),
            ])->save();

            return $run->fresh() ?? $run;
        }

        $office = Office::query()->findOrFail($run->office_id);
        $client = $run->client_id
            ? Client::query()->withoutGlobalScopes()->whereKey($run->client_id)->first()
            : null;

        $run->forceFill(['phase' => SerproEventosRun::PHASE_OBTAINING])->save();

        $businessData = strtoupper((string) $run->person_type) === 'PJ'
            ? $this->pjCodec->obtain((string) $run->protocol, (string) $run->evento)
            : ['protocolo' => $run->protocol, 'evento' => $run->evento];

        $response = $this->operations->run(new SerproOperationCommand(
            office: $office,
            client: $client,
            operationKey: (string) $run->operation_key_obter,
            businessData: $businessData,
            idempotencyKey: sprintf('eventos:obter:%d:%s', $run->office_id, $run->protocol),
            correlationId: $run->correlation_id,
            module: 'authorization',
            eventosBatchContributor: EventosBatchContributor::forObtain((string) $run->person_type),
        ));

        if ($response->httpStatus === 429) {
            $until = $this->limits->markRemote429(
                (int) $run->office_id,
                (string) $run->person_type,
                $response->retryAfterSeconds,
            );
            $run->forceFill([
                'phase' => SerproEventosRun::PHASE_RATE_LIMITED,
                'status' => SerproEventosRun::STATUS_RATE_LIMITED,
                'error_code' => 'RATE_LIMIT_EVENTOS_REMOTE_429',
                'error_message' => '429 remoto — sem retry até '.$until->toIso8601String(),
            ])->save();

            return $run->fresh() ?? $run;
        }

        if ($response->isStillProcessing()) {
            // Respeita TempoEsperaMedioEmMs já persistido; não inventa TTL
            $extraWaitMs = $run->tempo_espera_medio_ms
                ?? max(0, (int) config('serpro.eventos.fallback_wait_ms_if_omitted', 5000));
            $run->forceFill([
                'phase' => SerproEventosRun::PHASE_WAITING,
                'not_before_at' => CarbonImmutable::now()->addMilliseconds($extraWaitMs),
                'progress' => array_merge($run->progress ?? [], [
                    'still_processing' => true,
                    'last_http' => $response->httpStatus,
                ]),
            ])->save();

            return $run->fresh() ?? $run;
        }

        if (! $response->success) {
            $run->forceFill([
                'phase' => SerproEventosRun::PHASE_FAILED,
                'status' => SerproEventosRun::STATUS_FAILED,
                'error_code' => $response->errorCode ?? 'EVENTOS_OBTER_FAILED',
                'error_message' => mb_substr(
                    LogSanitizer::scrubString($response->errorMessage ?? 'Falha ao obter eventos.'),
                    0,
                    500
                ),
            ])->save();

            return $run->fresh() ?? $run;
        }

        // O 200 é one-shot: cifrar o payload antes de marcar consumo remoto.
        $artifact = $this->artifacts->store($run, $response->dados);

        $consumed = DB::transaction(function () use ($run, $response, $artifact) {
            $locked = SerproEventosRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->isOneShotConsumed()) {
                return $locked;
            }

            $locked->forceFill([
                'phase' => SerproEventosRun::PHASE_CONSUMED,
                'status' => SerproEventosRun::STATUS_RUNNING,
                'result_consumed' => true,
                'one_shot_complete' => true,
                'result_fingerprint' => $artifact['sha256'],
                'result_vault_object_id' => $artifact['object_id'],
                'result_payload_sha256' => $artifact['sha256'],
                'remote_result_received_at' => now(),
                'local_processing_status' => MailboxEventosResultProcessor::LOCAL_PENDING,
                'result_summary' => [
                    'http_status' => $response->httpStatus,
                    'business_status' => $response->businessStatus,
                    'mensagens_count' => count($response->mensagens),
                    'has_dados' => $response->dados !== null,
                    'simulated' => $response->simulated,
                    // sem PII / payload fiscal
                ],
                'obtained_at' => now(),
                'error_code' => null,
                'error_message' => null,
                'progress' => array_merge($locked->progress ?? [], [
                    'consumed_at' => now()->toIso8601String(),
                ]),
            ])->save();

            return $locked->fresh() ?? $locked;
        });

        return $this->processor->process($consumed);
    }

    /**
     * Segundos até poder obter (0 se já pode).
     */
    public function secondsUntilObtain(SerproEventosRun $run): int
    {
        if ($run->not_before_at === null) {
            return 0;
        }
        $diff = CarbonImmutable::now()->diffInSeconds($run->not_before_at, false);

        return $diff > 0 ? (int) $diff : 0;
    }

    /**
     * @return array{protocol: ?string, tempo_espera_medio_ms: ?int, tempo_limite_em_min: ?int}
     */
    private function parseSolicitPayload(IntegraResponse $response): array
    {
        $sources = [];
        if (is_array($response->body)) {
            $sources[] = $response->body;
        }
        if (is_array($response->dados)) {
            $sources[] = $response->dados;
        }
        if (is_string($response->dados)) {
            $decoded = json_decode($response->dados, true);
            if (is_array($decoded)) {
                $sources[] = $decoded;
            }
        }

        $protocol = null;
        $waitMs = null;
        $limitMin = null;

        foreach ($sources as $src) {
            foreach (['protocolo', 'protocol', 'Protocolo'] as $k) {
                if (isset($src[$k]) && is_scalar($src[$k]) && (string) $src[$k] !== '') {
                    $protocol = (string) $src[$k];
                    break;
                }
            }
            foreach (['TempoEsperaMedioEmMs', 'tempoEsperaMedioEmMs', 'tempo_espera_medio_ms'] as $k) {
                if (isset($src[$k]) && is_numeric($src[$k])) {
                    $waitMs = (int) $src[$k];
                    break;
                }
            }
            foreach (['TempoLimiteEmMin', 'tempoLimiteEmMin', 'tempo_limite_em_min'] as $k) {
                if (isset($src[$k]) && is_numeric($src[$k])) {
                    $limitMin = (int) $src[$k];
                    break;
                }
            }
        }

        return [
            'protocol' => $protocol,
            'tempo_espera_medio_ms' => $waitMs,
            'tempo_limite_em_min' => $limitMin,
        ];
    }

    private function persistFailedSolicit(
        Office $office,
        ?Client $client,
        SerproEnvironment $environment,
        string $personType,
        string $evento,
        string $correlationId,
        string $solicitKey,
        string $obterKey,
        int $contributorsCount,
        string $code,
        string $message,
        bool $simulated,
    ): SerproEventosRun {
        $status = str_contains($code, 'RATE_LIMIT') || str_contains($code, '429')
            ? SerproEventosRun::STATUS_RATE_LIMITED
            : SerproEventosRun::STATUS_FAILED;
        $phase = $status === SerproEventosRun::STATUS_RATE_LIMITED
            ? SerproEventosRun::PHASE_RATE_LIMITED
            : SerproEventosRun::PHASE_FAILED;

        return SerproEventosRun::query()->create([
            'office_id' => $office->id,
            'client_id' => $client?->id,
            'environment' => $environment,
            'person_type' => $personType,
            'phase' => $phase,
            'status' => $status,
            'correlation_id' => $correlationId,
            'operation_key_solicit' => $solicitKey,
            'operation_key_obter' => $obterKey,
            'evento' => $evento,
            'contributors_in_batch' => $contributorsCount,
            'error_code' => mb_substr($code, 0, 80),
            'error_message' => mb_substr(LogSanitizer::scrubString($message), 0, 500),
            'simulated' => $simulated,
            'solicited_at' => now(),
        ]);
    }
}
