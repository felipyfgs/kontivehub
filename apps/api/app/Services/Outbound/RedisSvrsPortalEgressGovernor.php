<?php

namespace App\Services\Outbound;

use App\Contracts\SvrsPortalEgressGovernor;
use App\Domain\Cnpj;
use App\DTO\Outbound\SvrsEgressReservation;
use App\DTO\Outbound\SvrsEgressReserveRequest;
use App\DTO\Outbound\SvrsEgressReserveResult;
use App\Enums\SvrsEgressBlockCause;
use App\Models\SvrsEgressCohortState;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Governador fail-closed: Redis para exclusão/janelas; PostgreSQL para breaker durável.
 */
final class RedisSvrsPortalEgressGovernor implements SvrsPortalEgressGovernor
{
    public function __construct(
        private readonly SvrsPortalEgressConfig $config,
        private readonly AuditLogger $audit,
    ) {}

    public function cohortId(): string
    {
        return $this->config->cohortId();
    }

    public function reserve(SvrsEgressReserveRequest $request): SvrsEgressReserveResult
    {
        $exchanges = $request->exchangesNeeded;
        if ($exchanges < 1) {
            return $this->denied('invalid_exchanges');
        }

        try {
            $this->ensureCohortRow();
            $this->refreshHalfOpenIfDue();

            if (! $this->isCallAllowed($request->isCanary)) {
                $health = $this->cohortHealth();
                $retry = 0;
                if (! empty($health['next_probe_at'])) {
                    $retry = max(0, strtotime((string) $health['next_probe_at']) - time());
                }

                return $this->denied('breaker_open', $retry > 0 ? $retry : 900);
            }

            $lock = Cache::lock($this->mutexKey(), 5);
            if (! $lock->get()) {
                return $this->denied('mutex', 2);
            }

            try {
                $now = microtime(true);

                $inflight = (int) Cache::get($this->inflightKey(), 0);
                if ($inflight >= $this->config->maxInflightTransactions()) {
                    return $this->denied('inflight', 5);
                }

                $lastGlobal = (float) Cache::get($this->globalLastKey(), 0.0);
                $globalWait = $this->config->minIntervalGlobalSeconds() - ($now - $lastGlobal);
                if ($globalWait > 0) {
                    return $this->denied('global_interval', (int) ceil($globalWait));
                }

                $rootKey = $this->normalizeRoot($request->rootCnpj);
                $lastRoot = (float) Cache::get($this->rootLastKey($rootKey), 0.0);
                $rootWait = $this->config->minIntervalRootSeconds() - ($now - $lastRoot);
                if ($rootWait > 0) {
                    return $this->denied('root_interval', (int) ceil($rootWait));
                }

                $hourUsed = $this->windowCount($this->hourKey());
                if ($hourUsed + $exchanges > $this->config->maxExchangesPerHour()) {
                    return $this->denied('hour_budget', $this->secondsToNextHour());
                }

                $dayUsed = $this->windowCount($this->dayKey());
                if ($dayUsed + $exchanges > $this->config->maxExchangesPerDay()) {
                    return $this->denied('day_budget', $this->secondsToNextDay());
                }

                $rootDayKey = $this->rootDayKey($rootKey);
                $rootKeysToday = $this->windowCount($rootDayKey);
                if ($rootKeysToday + 1 > $this->config->maxKeysPerRootPerDay()) {
                    return $this->denied('root_day_keys', $this->secondsToNextDay());
                }

                // Reserva atômica sob mutex: inflight + intervalos + pré-débito de budgets.
                // Budgets hora/dia são burn-on-reserve: NÃO reembolsados em release (falha
                // antes do POST ainda consome orçamento preventivo — ver release()).
                Cache::put($this->inflightKey(), $inflight + 1, $this->config->reservationTtlSeconds() + 30);
                Cache::put($this->globalLastKey(), $now, 86400);
                Cache::put($this->rootLastKey($rootKey), $now, 86400);
                $this->windowIncrement($this->hourKey(), $exchanges, 3700);
                $this->windowIncrement($this->dayKey(), $exchanges, 90000);
                $this->windowIncrement($rootDayKey, 1, 90000);

                $reservation = new SvrsEgressReservation(
                    id: (string) Str::uuid(),
                    cohortId: $this->cohortId(),
                    rootCnpj: $rootKey,
                    channel: $request->channel,
                    officeId: $request->officeId,
                    exchangesReserved: $exchanges,
                    exchangesConsumed: 0,
                );

                Cache::put(
                    $this->reservationKey($reservation->id),
                    [
                        'root' => $rootKey,
                        'channel' => $request->channel,
                        'office_id' => $request->officeId,
                        'reserved' => $exchanges,
                        'consumed' => 0,
                        'created_at' => time(),
                    ],
                    $this->config->reservationTtlSeconds()
                );

                $this->metric('svrs_egress_reservations', 1, [
                    'channel' => $request->channel,
                    'decision' => 'allowed',
                ]);
                $this->metric('svrs_egress_exchanges_reserved', $exchanges, [
                    'channel' => $request->channel,
                ]);

                return SvrsEgressReserveResult::allow($reservation);
            } finally {
                $lock->release();
            }
        } catch (Throwable $e) {
            Log::warning('svrs_egress.reserve.fail_closed', [
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return $this->denied('coordinator_unavailable', 60);
        }
    }

    public function consumeExchange(SvrsEgressReservation $reservation, string $kind): void
    {
        if (! in_array($kind, ['GET', 'POST', 'REDIRECT'], true)) {
            throw new \InvalidArgumentException('kind inválido.');
        }

        $key = $this->reservationKey($reservation->id);
        $data = Cache::get($key);
        if (! is_array($data)) {
            throw new \RuntimeException('Reserva ausente ou expirada — fail closed.');
        }
        $consumed = (int) ($data['consumed'] ?? 0) + 1;
        if ($consumed > (int) ($data['reserved'] ?? 0)) {
            throw new \RuntimeException('Exchanges da reserva esgotados.');
        }
        $data['consumed'] = $consumed;
        Cache::put($key, $data, $this->config->reservationTtlSeconds());
        $reservation->exchangesConsumed = $consumed;
        $this->metric('svrs_egress_exchanges', 1, [
            'channel' => $reservation->channel,
            'exchange_kind' => $kind,
        ]);

        try {
            SvrsEgressCohortState::query()
                ->where('cohort_id', $this->cohortId())
                ->update(['last_exchange_at' => now()]);
        } catch (Throwable) {
            // best-effort
        }
    }

    /**
     * Libera inflight da reserva.
     *
     * Budgets hora/dia: burn-on-reserve intencional — exchanges pré-debitados em
     * {@see reserve()} não são reembolsados aqui (nem os não consumidos via
     * consumeExchange). Preferimos sub-utilizar a janela a reabrir orçamento após
     * falha pré-POST e arriscar rajada no portal SVRS.
     *
     * Inflight: exige mutex (mesmo de reserve). Sem exclusão mútua, fail-closed —
     * não faz get/put não-atômico (que inflaria inflight sob contenção). A chave
     * inflight expira por TTL e a reserva é descartada.
     */
    public function release(SvrsEgressReservation $reservation, bool $completed = false): void
    {
        unset($completed); // reservado para telemetria futura; budgets não dependem

        $lock = Cache::lock($this->mutexKey(), 5);
        $got = false;
        try {
            // Espera breve pelo mutex (reserve em voo); evita path não-atômico.
            $got = (bool) $lock->block(3);
        } catch (LockTimeoutException) {
            $got = false;
        } catch (Throwable $e) {
            Log::warning('svrs_egress.release.lock_error', [
                'reservation_id' => $reservation->id,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
            $got = false;
        }

        if (! $got) {
            // Fail-closed: não mutar inflight sem mutex. Prefere sub-capacidade
            // temporária (TTL da chave) a contagem inflada sob contenção.
            Log::warning('svrs_egress.release.mutex_unavailable', [
                'reservation_id' => $reservation->id,
                'cohort_id' => $reservation->cohortId,
            ]);
            Cache::forget($this->reservationKey($reservation->id));

            return;
        }

        try {
            $inflight = (int) Cache::get($this->inflightKey(), 0);
            Cache::put($this->inflightKey(), max(0, $inflight - 1), $this->config->reservationTtlSeconds() + 30);
            Cache::forget($this->reservationKey($reservation->id));
        } finally {
            $lock->release();
        }
    }

    public function openBreaker(
        SvrsEgressBlockCause $cause,
        ?string $templateFingerprint = null,
        ?int $retryAfterSeconds = null,
        ?int $userId = null,
        ?int $officeId = null,
    ): void {
        $ladder = $this->config->blockCooldownLadderSeconds();
        $row = $this->ensureCohortRow();

        $tier = (int) $row->tier;
        if ($cause === SvrsEgressBlockCause::MultipleQueries
            || $cause === SvrsEgressBlockCause::RateHttp
            || $cause === SvrsEgressBlockCause::ContractChanged) {
            // reincidência: sobe patamar se já estava open/half_open recentemente
            if (in_array($row->state, ['open', 'half_open'], true) || $tier > 0) {
                $tier = min(count($ladder) - 1, $tier + 1);
            } else {
                $tier = 0;
            }
        }

        $cooldown = $ladder[$tier] ?? $ladder[array_key_last($ladder)];
        if ($retryAfterSeconds !== null && $retryAfterSeconds > $cooldown) {
            $cooldown = $retryAfterSeconds;
        }

        $row->forceFill([
            'state' => 'open',
            'cause' => $cause,
            'tier' => $tier,
            'opened_at' => now(),
            'next_probe_at' => now()->addSeconds($cooldown),
            'template_fingerprint' => $templateFingerprint ? mb_substr($templateFingerprint, 0, 64) : $row->template_fingerprint,
            'canary_access_key_hash' => null,
            'canary_key_mask' => null,
        ])->save();

        Cache::put($this->breakerInvalidateKey(), time(), 86400);

        $this->audit->record('svrs_egress.breaker.open', 'SUCCESS', null, [
            'cohort_id' => $this->cohortId(),
            'cause' => $cause->value,
            'tier' => $tier,
            'cooldown_seconds' => $cooldown,
            'fingerprint' => $templateFingerprint ? mb_substr($templateFingerprint, 0, 16) : null,
        ], $userId, $officeId);
        $this->metric('svrs_egress_breaker_open', 1, [
            'cause' => $cause->value,
            'state' => 'open',
        ]);
    }

    public function closeBreakerAfterCanarySuccess(?int $userId = null, ?int $officeId = null): void
    {
        $row = $this->ensureCohortRow();
        $row->forceFill([
            'state' => 'closed',
            'cause' => null,
            'tier' => 0,
            'opened_at' => null,
            'next_probe_at' => null,
            'canary_access_key_hash' => null,
            'canary_key_mask' => null,
            'template_fingerprint' => null,
        ])->save();

        Cache::put($this->breakerInvalidateKey(), time(), 86400);

        $this->audit->record('svrs_egress.breaker.close', 'SUCCESS', null, [
            'cohort_id' => $this->cohortId(),
            'reason' => 'canary_success',
        ], $userId, $officeId);
        $this->metric('svrs_egress_canary_result', 1, [
            'outcome' => 'success',
            'state' => 'closed',
        ]);
    }

    public function extendCooldown(int $additionalSeconds, int $userId, ?int $officeId = null): void
    {
        if ($additionalSeconds < 1) {
            throw new \InvalidArgumentException('additionalSeconds deve ser positivo.');
        }
        $row = $this->ensureCohortRow();
        $base = $row->next_probe_at && $row->next_probe_at->isFuture()
            ? $row->next_probe_at
            : now();
        $row->forceFill([
            'state' => 'open',
            'next_probe_at' => $base->copy()->addSeconds($additionalSeconds),
        ])->save();

        $this->audit->record('svrs_egress.cooldown.extend', 'SUCCESS', null, [
            'cohort_id' => $this->cohortId(),
            'additional_seconds' => $additionalSeconds,
        ], $userId, $officeId);
        $this->metric('svrs_egress_cooldown_extended', 1, [
            'state' => 'open',
        ]);
    }

    public function cohortHealth(): array
    {
        $row = $this->ensureCohortRow();
        $this->refreshHalfOpenIfDue();
        $row->refresh();

        $hourUsed = $this->windowCount($this->hourKey());
        $dayUsed = $this->windowCount($this->dayKey());

        return [
            'cohort_id' => $this->cohortId(),
            'state' => (string) $row->state,
            'cause' => $row->cause?->value,
            'tier' => (int) $row->tier,
            'opened_at' => $row->opened_at?->toIso8601String(),
            'next_probe_at' => $row->next_probe_at?->toIso8601String(),
            'canary_key_mask' => $row->canary_key_mask,
            'exchanges_hour' => $hourUsed,
            'exchanges_day' => $dayUsed,
            'exchanges_hour_remaining' => max(0, $this->config->maxExchangesPerHour() - $hourUsed),
            'exchanges_day_remaining' => max(0, $this->config->maxExchangesPerDay() - $dayUsed),
            'inflight' => (int) Cache::get($this->inflightKey(), 0),
        ];
    }

    public function isCallAllowed(bool $isCanary = false): bool
    {
        try {
            $row = $this->ensureCohortRow();
            $this->refreshHalfOpenIfDue();
            $row->refresh();

            if ($row->state === 'closed') {
                return true;
            }
            if ($row->state === 'open') {
                return false;
            }

            // half_open: somente canário
            return $isCanary;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Seleciona canário elegível (ADMIN). Não antecipa next_probe_at.
     *
     * @return array{ok: bool, reason: string}
     */
    public function selectCanary(string $accessKeyMask, string $accessKeyHash, int $userId, ?int $officeId = null): array
    {
        $row = $this->ensureCohortRow();
        $this->refreshHalfOpenIfDue();
        $row->refresh();

        if ($row->state === 'open') {
            $this->metric('svrs_egress_canary_selection', 1, ['decision' => 'denied_cooldown']);

            return ['ok' => false, 'reason' => 'cooldown_active'];
        }
        if ($row->state !== 'half_open' && $row->state !== 'closed') {
            $this->metric('svrs_egress_canary_selection', 1, ['decision' => 'denied_state']);

            return ['ok' => false, 'reason' => 'invalid_state'];
        }

        $row->forceFill([
            'state' => 'half_open',
            'canary_key_mask' => mb_substr($accessKeyMask, 0, 20),
            'canary_access_key_hash' => mb_substr($accessKeyHash, 0, 64),
        ])->save();

        $this->audit->record('svrs_egress.canary.select', 'SUCCESS', null, [
            'cohort_id' => $this->cohortId(),
            'key_mask' => mb_substr($accessKeyMask, 0, 20),
        ], $userId, $officeId);
        $this->metric('svrs_egress_canary_selection', 1, ['decision' => 'selected']);

        return ['ok' => true, 'reason' => 'selected'];
    }

    public function assertChannelMayEnable(): void
    {
        if (! $this->config->anyPortalChannelEnabled()) {
            return;
        }

        // cohort_id obrigatório quando canal ligado
        $this->config->cohortId();

        if (! $this->config->requireSharedCoordinator()) {
            return;
        }

        // Garante que Redis responde (coordenador)
        try {
            Cache::put($this->prefix().'health', 1, 10);
            if (Cache::get($this->prefix().'health') !== 1) {
                throw new \RuntimeException('Redis não confirmou escrita.');
            }
        } catch (Throwable $e) {
            throw new \RuntimeException(
                'Canal SVRS portal exige coordenador Redis compartilhado (SVRS_EGRESS_COHORT_ID). '.$e->getMessage(),
                0,
                $e
            );
        }

        $row = $this->ensureCohortRow();
        $deployment = $this->config->deploymentId();
        if ($row->active_deployment_id !== null
            && $row->active_deployment_id !== ''
            && $row->active_deployment_id !== $deployment
            && $this->config->requireSharedCoordinator()) {
            // Mesma coorte com deployment distinto: permitido se Redis é o coordenador comum.
            // Registramos o deployment ativo sem bloquear se Redis ok.
        }
        $row->forceFill(['active_deployment_id' => $deployment])->save();
    }

    private function refreshHalfOpenIfDue(): void
    {
        $row = SvrsEgressCohortState::query()->where('cohort_id', $this->cohortId())->first();
        if ($row === null || $row->state !== 'open') {
            return;
        }
        if ($row->next_probe_at !== null && $row->next_probe_at->isPast()) {
            $row->forceFill(['state' => 'half_open'])->save();
        }
    }

    private function ensureCohortRow(): SvrsEgressCohortState
    {
        return SvrsEgressCohortState::query()->firstOrCreate(
            ['cohort_id' => $this->cohortId()],
            [
                'state' => 'closed',
                'tier' => 0,
                'active_deployment_id' => $this->config->deploymentId(),
            ]
        );
    }

    /**
     * Normaliza raiz de CNPJ: alfanumérico maiúsculo (mesma regra de {@see Cnpj::normalize}).
     * CNPJ completo (14) → 8 primeiros (raiz); raiz já com 8 ou chave sintética (CLIENT…) mantida.
     */
    private function normalizeRoot(string $rootCnpj): string
    {
        $clean = Cnpj::normalize($rootCnpj);
        if ($clean === '') {
            return strtoupper($rootCnpj);
        }

        if (strlen($clean) === 14) {
            $parsed = Cnpj::tryParse($clean);

            return $parsed !== null ? $parsed->root() : substr($clean, 0, 8);
        }

        return $clean;
    }

    private function prefix(): string
    {
        return 'svrs.egress.'.$this->cohortId().'.';
    }

    private function mutexKey(): string
    {
        return $this->prefix().'mutex';
    }

    private function inflightKey(): string
    {
        return $this->prefix().'inflight';
    }

    private function globalLastKey(): string
    {
        return $this->prefix().'last_global';
    }

    private function rootLastKey(string $root): string
    {
        return $this->prefix().'last_root.'.hash('sha256', $root);
    }

    private function hourKey(): string
    {
        return $this->prefix().'ex_h.'.gmdate('YmdH');
    }

    private function dayKey(): string
    {
        return $this->prefix().'ex_d.'.gmdate('Ymd');
    }

    private function rootDayKey(string $root): string
    {
        return $this->prefix().'keys_d.'.gmdate('Ymd').'.'.hash('sha256', $root);
    }

    private function reservationKey(string $id): string
    {
        return $this->prefix().'res.'.$id;
    }

    private function breakerInvalidateKey(): string
    {
        return $this->prefix().'breaker_epoch';
    }

    private function windowCount(string $key): int
    {
        return (int) Cache::get($key, 0);
    }

    private function windowIncrement(string $key, int $by, int $ttl): void
    {
        $v = (int) Cache::get($key, 0);
        Cache::put($key, $v + $by, $ttl);
    }

    private function secondsToNextHour(): int
    {
        return max(1, 3600 - (time() % 3600));
    }

    private function secondsToNextDay(): int
    {
        $tomorrow = strtotime('tomorrow UTC');

        return max(1, $tomorrow - time());
    }

    private function denied(string $reason, int $retryAfterSeconds = 0): SvrsEgressReserveResult
    {
        $this->metric('svrs_egress_reservations', 1, [
            'decision' => 'denied',
            'cause' => $reason,
        ]);

        return SvrsEgressReserveResult::deny($reason, $retryAfterSeconds);
    }

    /**
     * Métrica best-effort com labels de baixa cardinalidade; nunca interfere no governador.
     *
     * @param  array<string, scalar|null>  $labels
     */
    private function metric(string $name, int $by = 1, array $labels = []): void
    {
        try {
            app(OutboundMetrics::class)->increment($name, $by, $labels);
        } catch (Throwable) {
            // observabilidade não pode alterar a decisão fail-closed
        }
    }
}
