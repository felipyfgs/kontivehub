<?php

namespace App\Services\Integra\Sitfis;

use Carbon\CarbonImmutable;

/**
 * Estado correlacionado do fluxo SITFIS (persistido em fiscal_monitoring_runs.progress).
 *
 * @phpstan-type ProgressArray array{
 *     phase?: string,
 *     protocol?: string|null,
 *     requested_at?: string|null,
 *     not_before?: string|null,
 *     poll_count?: int,
 *     last_poll_at?: string|null,
 *     correlation_id?: string|null,
 *     requeue_after_seconds?: int,
 *     simulated?: bool
 * }
 */
final class SitfisProtocolState
{
    public const PHASE_IDLE = 'IDLE';

    public const PHASE_WAITING_MIN_PERIOD = 'WAITING_MIN_PERIOD';

    /** Aguardando expiração do cache /Apoiar (HTTP 304 sem protocolo parseável). */
    public const PHASE_WAITING_CACHE_EXPIRY = 'WAITING_CACHE_EXPIRY';

    public const PHASE_POLLING_EMIT = 'POLLING_EMIT';

    public const PHASE_DONE = 'DONE';

    public const PHASE_FAILED = 'FAILED';

    public function __construct(
        public readonly string $phase,
        public readonly ?string $protocol = null,
        public readonly ?CarbonImmutable $requestedAt = null,
        public readonly ?CarbonImmutable $notBefore = null,
        public readonly int $pollCount = 0,
        public readonly ?CarbonImmutable $lastPollAt = null,
        public readonly ?string $correlationId = null,
        public readonly int $requeueAfterSeconds = 0,
        public readonly bool $simulated = false,
    ) {}

    /**
     * @param  array<string, mixed>  $progress
     */
    public static function fromProgress(array $progress): self
    {
        return new self(
            phase: (string) ($progress['phase'] ?? self::PHASE_IDLE),
            protocol: isset($progress['protocol']) ? (string) $progress['protocol'] : null,
            requestedAt: self::parseTime($progress['requested_at'] ?? null),
            notBefore: self::parseTime($progress['not_before'] ?? null),
            pollCount: (int) ($progress['poll_count'] ?? 0),
            lastPollAt: self::parseTime($progress['last_poll_at'] ?? null),
            correlationId: isset($progress['correlation_id']) ? (string) $progress['correlation_id'] : null,
            requeueAfterSeconds: (int) ($progress['requeue_after_seconds'] ?? 0),
            simulated: (bool) ($progress['simulated'] ?? false),
        );
    }

    public function hasProtocol(): bool
    {
        return $this->protocol !== null && $this->protocol !== '';
    }

    public function canAttemptEmit(CarbonImmutable $now): bool
    {
        if (! $this->hasProtocol()) {
            return false;
        }
        if ($this->notBefore === null) {
            return true;
        }

        return $now->greaterThanOrEqualTo($this->notBefore);
    }

    public function secondsUntilEmitAllowed(CarbonImmutable $now): int
    {
        if ($this->notBefore === null) {
            return 0;
        }

        $diff = $now->diffInSeconds($this->notBefore, false);

        return $diff > 0 ? (int) $diff : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toProgress(): array
    {
        return array_filter([
            'phase' => $this->phase,
            'protocol' => $this->protocol,
            'requested_at' => $this->requestedAt?->toIso8601String(),
            'not_before' => $this->notBefore?->toIso8601String(),
            'poll_count' => $this->pollCount,
            'last_poll_at' => $this->lastPollAt?->toIso8601String(),
            'correlation_id' => $this->correlationId,
            'requeue_after_seconds' => $this->requeueAfterSeconds,
            'simulated' => $this->simulated,
        ], static fn ($v) => $v !== null);
    }

    public function with(
        ?string $phase = null,
        ?string $protocol = null,
        ?CarbonImmutable $requestedAt = null,
        ?CarbonImmutable $notBefore = null,
        ?int $pollCount = null,
        ?CarbonImmutable $lastPollAt = null,
        ?string $correlationId = null,
        ?int $requeueAfterSeconds = null,
        ?bool $simulated = null,
    ): self {
        return new self(
            phase: $phase ?? $this->phase,
            protocol: $protocol ?? $this->protocol,
            requestedAt: $requestedAt ?? $this->requestedAt,
            notBefore: $notBefore ?? $this->notBefore,
            pollCount: $pollCount ?? $this->pollCount,
            lastPollAt: $lastPollAt ?? $this->lastPollAt,
            correlationId: $correlationId ?? $this->correlationId,
            requeueAfterSeconds: $requeueAfterSeconds ?? $this->requeueAfterSeconds,
            simulated: $simulated ?? $this->simulated,
        );
    }

    private static function parseTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
