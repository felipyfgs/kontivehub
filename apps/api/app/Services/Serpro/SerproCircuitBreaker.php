<?php

namespace App\Services\Serpro;

use App\Models\SerproCircuitBreakerState;
use App\Services\Audit\AuditLogger;
use App\Services\Operations\OperationsMetrics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Circuit breaker atômico por dependência/solução.
 *
 * - Half-open com probes limitados (config half_open_max_probes)
 * - Somente falhas técnicas contam (não 403 de negócio)
 * - Estado crítico persistido em serpro_circuit_breaker_states
 */
final class SerproCircuitBreaker
{
    private const GLOBAL_KEY = 'serpro.breaker.global';

    private const SOLUTION_PREFIX = 'serpro.breaker.solution.';

    private const PROBE_PREFIX = 'serpro.breaker.probe.';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function isCallAllowed(?string $solutionCode = null, bool $isProbe = false): bool
    {
        if (! $this->scopeAllows(self::GLOBAL_KEY, $isProbe)) {
            return false;
        }

        if ($solutionCode !== null && ! $this->scopeAllows(self::SOLUTION_PREFIX.strtoupper($solutionCode), $isProbe)) {
            return false;
        }

        if ($isProbe) {
            return $this->acquireProbeSlot($solutionCode);
        }

        // Em half-open, só probes passam
        $global = $this->status(self::GLOBAL_KEY);
        if ($global['state'] === 'half_open') {
            return false;
        }
        if ($solutionCode !== null) {
            $sol = $this->status(self::SOLUTION_PREFIX.strtoupper($solutionCode));
            if ($sol['state'] === 'half_open') {
                return false;
            }
        }

        return true;
    }

    public function recordSuccess(?string $solutionCode = null): void
    {
        $this->releaseProbeSlot($solutionCode);
        $this->close(self::GLOBAL_KEY);
        if ($solutionCode !== null) {
            $this->close(self::SOLUTION_PREFIX.strtoupper($solutionCode));
        }
    }

    /**
     * @param  bool  $technicalFailure  false para erros de negócio (ex.: 403 autorização) — não tripam o breaker técnico
     */
    public function recordFailure(
        ?string $solutionCode = null,
        ?string $reason = null,
        bool $technicalFailure = true,
    ): void {
        $this->releaseProbeSlot($solutionCode);

        if (! $technicalFailure) {
            return;
        }

        $this->incrementTowardTrip(self::GLOBAL_KEY, $reason);
        if ($solutionCode !== null) {
            $this->incrementTowardTrip(self::SOLUTION_PREFIX.strtoupper($solutionCode), $reason);
        }
    }

    /**
     * Classifica se o HTTP/erro deve contar como falha técnica do breaker.
     */
    public function isTechnicalFailure(?int $httpStatus, ?string $errorCode = null): bool
    {
        if ($errorCode !== null) {
            $code = strtoupper($errorCode);
            if (in_array($code, ['TRANSPORT_ERROR', 'TIMEOUT', 'TLS_ERROR', 'DNS_ERROR'], true)) {
                return true;
            }
            if (in_array($code, ['AUTH_FORBIDDEN', 'BUSINESS_403', 'AUTHORIZATION_DENIED'], true)) {
                return false;
            }
        }

        if ($httpStatus === null || $httpStatus === 0) {
            return true;
        }

        // 403 de negócio: bilhetável, não é indisponibilidade técnica global
        if ($httpStatus === 403) {
            return false;
        }

        // 401 pode ser credencial; conta como técnico para proteção
        if ($httpStatus === 401 || $httpStatus === 429) {
            return true;
        }

        return $httpStatus >= 500 || $httpStatus === 408 || $httpStatus === 504;
    }

    public function trip(string $scope, string $reason, ?int $userId = null): void
    {
        $openSeconds = (int) config('serpro.circuit_breaker.open_seconds', 120);
        $payload = [
            'state' => 'open',
            'open_until' => now()->addSeconds($openSeconds)->getTimestamp(),
            'failures' => (int) config('serpro.circuit_breaker.failure_threshold', 5),
            'half_open_probes' => 0,
            'reason' => mb_substr($reason, 0, 200),
        ];
        Cache::put($scope, $payload, $openSeconds + 60);
        $this->persistState($scope, $payload);

        $this->audit->record('serpro.breaker.trip', 'SUCCESS', null, [
            'scope' => $scope,
            'reason' => mb_substr($reason, 0, 200),
            'breaker_state' => 'open',
        ], $userId, null);

        try {
            app(OperationsMetrics::class)->increment(
                'serpro.breaker.trip',
                1,
                ['breaker_state' => 'open', 'channel' => 'serpro'],
            );
        } catch (\Throwable) {
            // métricas não derrubam breaker
        }
    }

    public function resetGlobal(string $reason, ?int $userId = null): void
    {
        $this->close(self::GLOBAL_KEY);
        $this->audit->record('serpro.breaker.global_reset', 'SUCCESS', null, [
            'reason' => mb_substr($reason, 0, 200),
        ], $userId, null);
    }

    /**
     * @return array{state: string, open_until: ?int, failures: int, half_open_probes?: int}
     */
    public function globalStatus(): array
    {
        return $this->status(self::GLOBAL_KEY);
    }

    /**
     * @return array{state: string, open_until: ?int, failures: int, half_open_probes?: int}
     */
    public function solutionStatus(string $solutionCode): array
    {
        return $this->status(self::SOLUTION_PREFIX.strtoupper($solutionCode));
    }

    /**
     * @return array{state: string, open_until: ?int, failures: int, half_open_probes: int}
     */
    private function status(string $key): array
    {
        /** @var array{state?: string, open_until?: int, failures?: int, half_open_probes?: int}|null $data */
        $data = Cache::get($key);
        if (! is_array($data)) {
            $data = $this->loadPersisted($key);
        }
        if (! is_array($data)) {
            return ['state' => 'closed', 'open_until' => null, 'failures' => 0, 'half_open_probes' => 0];
        }

        $openUntil = isset($data['open_until']) ? (int) $data['open_until'] : null;
        $state = (string) ($data['state'] ?? 'closed');

        if ($state === 'open' && $openUntil !== null && $openUntil <= time()) {
            $half = [
                'state' => 'half_open',
                'open_until' => $openUntil,
                'failures' => (int) ($data['failures'] ?? 0),
                'half_open_probes' => 0,
            ];
            Cache::put($key, $half, 600);
            $this->persistState($key, $half);

            return $half;
        }

        return [
            'state' => $state,
            'open_until' => $openUntil,
            'failures' => (int) ($data['failures'] ?? 0),
            'half_open_probes' => (int) ($data['half_open_probes'] ?? 0),
        ];
    }

    private function scopeAllows(string $key, bool $isProbe): bool
    {
        $status = $this->status($key);

        return match ($status['state']) {
            'open' => false,
            'half_open' => $isProbe,
            'closed' => true,
            default => true,
        };
    }

    private function acquireProbeSlot(?string $solutionCode): bool
    {
        $max = max(1, (int) config('serpro.circuit_breaker.half_open_max_probes', 1));
        $probeKey = self::PROBE_PREFIX.($solutionCode !== null ? strtoupper($solutionCode) : 'global');

        Cache::add($probeKey, 0, 120);
        $n = (int) Cache::increment($probeKey);

        return $n <= $max;
    }

    private function releaseProbeSlot(?string $solutionCode): void
    {
        $probeKey = self::PROBE_PREFIX.($solutionCode !== null ? strtoupper($solutionCode) : 'global');
        Cache::forget($probeKey);
    }

    private function close(string $key): void
    {
        Cache::forget($key);
        $payload = [
            'state' => 'closed',
            'open_until' => null,
            'failures' => 0,
            'half_open_probes' => 0,
            'reason' => 'closed',
        ];
        $this->persistState($key, $payload);
    }

    private function incrementTowardTrip(string $key, ?string $reason): void
    {
        $threshold = (int) config('serpro.circuit_breaker.failure_threshold', 5);
        /** @var array{state?: string, open_until?: int, failures?: int}|null $data */
        $data = Cache::get($key);
        if (! is_array($data)) {
            $data = $this->loadPersisted($key) ?? [];
        }
        $failures = ((int) ($data['failures'] ?? 0)) + 1;

        if ($failures >= $threshold) {
            $this->trip($key, $reason ?? 'threshold', null);

            return;
        }

        $payload = [
            'state' => 'closed',
            'open_until' => null,
            'failures' => $failures,
            'half_open_probes' => 0,
            'reason' => $reason,
        ];
        Cache::put($key, $payload, 600);
        $this->persistState($key, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistState(string $scope, array $payload): void
    {
        if (! Schema::hasTable('serpro_circuit_breaker_states')) {
            return;
        }

        try {
            $solution = null;
            $dependency = 'SERPRO';
            if (str_starts_with($scope, self::SOLUTION_PREFIX)) {
                $solution = substr($scope, strlen(self::SOLUTION_PREFIX));
            }

            SerproCircuitBreakerState::query()->updateOrCreate(
                ['scope_key' => $scope],
                [
                    'dependency' => $dependency,
                    'solution_code' => $solution,
                    'state' => (string) ($payload['state'] ?? 'closed'),
                    'failures' => (int) ($payload['failures'] ?? 0),
                    'half_open_probes' => (int) ($payload['half_open_probes'] ?? 0),
                    'open_until' => isset($payload['open_until']) && $payload['open_until']
                        ? now()->setTimestamp((int) $payload['open_until'])
                        : null,
                    'last_reason' => isset($payload['reason'])
                        ? mb_substr((string) $payload['reason'], 0, 200)
                        : null,
                    'metadata' => ['cache_scope' => $scope],
                ],
            );
        } catch (\Throwable) {
            // persistência não derruba o breaker em cache
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPersisted(string $scope): ?array
    {
        if (! Schema::hasTable('serpro_circuit_breaker_states')) {
            return null;
        }

        try {
            $row = SerproCircuitBreakerState::query()->where('scope_key', $scope)->first();
            if ($row === null) {
                return null;
            }

            return [
                'state' => $row->state,
                'open_until' => $row->open_until?->getTimestamp(),
                'failures' => (int) $row->failures,
                'half_open_probes' => (int) $row->half_open_probes,
                'reason' => $row->last_reason,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
