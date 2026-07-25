<?php

namespace App\Services\Outbound;

use App\Enums\SvrsNfceFailureReason;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;

/**
 * Breaker global (contrato sistêmico) e por raiz (A1/identidade).
 * AuthForbidden conta threshold global; ResponseContractChanged trip imediato.
 * Estados: closed | open | half_open.
 */
final class SvrsNfceCircuitBreaker
{
    private const GLOBAL_KEY = 'sefaz.svrs_nfce_xml.breaker.global';

    private const ROOT_PREFIX = 'sefaz.svrs_nfce_xml.breaker.root.';

    private const PROBE_SLOT_KEY = 'sefaz.svrs_nfce_xml.breaker.probe_slot';

    public function __construct(
        private readonly SvrsNfceConfig $config,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{state: string, open_until: ?int, failures: int}
     */
    public function globalStatus(): array
    {
        return $this->status(self::GLOBAL_KEY);
    }

    /**
     * @return array{state: string, open_until: ?int, failures: int}
     */
    public function rootStatus(int $clientId): array
    {
        return $this->status(self::ROOT_PREFIX.$clientId);
    }

    /**
     * @param  bool  $isProbe  half-open: apenas uma prova por vez (slot global)
     */
    public function isCallAllowed(?int $clientId = null, bool $isProbe = false): bool
    {
        if (! $this->scopeAllows(self::GLOBAL_KEY, $isProbe)) {
            return false;
        }
        if ($clientId !== null && ! $this->scopeAllows(self::ROOT_PREFIX.$clientId, $isProbe)) {
            return false;
        }

        if ($isProbe) {
            // Uma única prova half-open por vez na instância
            $acquired = Cache::add(self::PROBE_SLOT_KEY, 1, 120);
            if (! $acquired) {
                return false;
            }
        }

        return true;
    }

    public function recordSuccess(?int $clientId = null): void
    {
        Cache::forget(self::PROBE_SLOT_KEY);
        $this->close(self::GLOBAL_KEY, 'success');
        if ($clientId !== null) {
            $this->close(self::ROOT_PREFIX.$clientId, 'success');
        }
    }

    public function recordFailure(SvrsNfceFailureReason $reason, ?int $clientId = null, ?int $userId = null, ?int $officeId = null): void
    {
        Cache::forget(self::PROBE_SLOT_KEY);

        if ($reason->opensGlobalBreaker()) {
            $this->trip(self::GLOBAL_KEY, $reason->value, $userId, $officeId);
        } elseif ($reason->countsTowardGlobalThreshold()) {
            $this->incrementTowardTrip(self::GLOBAL_KEY, $reason->value, $userId, $officeId);
        }

        if ($clientId !== null && $reason->opensRootBreaker()) {
            $this->trip(self::ROOT_PREFIX.$clientId, $reason->value, $userId, $officeId);
        }
    }

    public function resetGlobal(string $reason, int $userId, ?int $officeId = null): void
    {
        Cache::forget(self::PROBE_SLOT_KEY);
        $this->close(self::GLOBAL_KEY, $reason);
        $this->audit->record('svrs_nfce.breaker.global_reset', 'SUCCESS', null, [
            'reason' => mb_substr($reason, 0, 500),
        ], $userId, $officeId);
    }

    public function resetRoot(int $clientId, string $reason, int $userId, ?int $officeId = null): void
    {
        $this->close(self::ROOT_PREFIX.$clientId, $reason);
        $this->audit->record('svrs_nfce.breaker.root_reset', 'SUCCESS', null, [
            'client_id' => $clientId,
            'reason' => mb_substr($reason, 0, 500),
        ], $userId, $officeId);
    }

    /**
     * @return array{state: string, open_until: ?int, failures: int}
     */
    private function status(string $key): array
    {
        $data = Cache::get($key, ['state' => 'closed', 'open_until' => null, 'failures' => 0]);
        $state = (string) ($data['state'] ?? 'closed');
        $openUntil = isset($data['open_until']) ? (int) $data['open_until'] : null;

        if ($state === 'open' && $openUntil !== null && time() >= $openUntil) {
            $data = ['state' => 'half_open', 'open_until' => null, 'failures' => (int) ($data['failures'] ?? 0)];
            Cache::put($key, $data, $this->config->breakerOpenSeconds() * 2);
            $state = 'half_open';
            $openUntil = null;
        }

        return [
            'state' => $state,
            'open_until' => $openUntil,
            'failures' => (int) ($data['failures'] ?? 0),
        ];
    }

    private function scopeAllows(string $key, bool $isProbe): bool
    {
        $st = $this->status($key);
        if ($st['state'] === 'closed') {
            return true;
        }
        if ($st['state'] === 'half_open') {
            return $isProbe;
        }

        return false;
    }

    private function trip(string $key, string $reason, ?int $userId, ?int $officeId): void
    {
        $openUntil = time() + $this->config->breakerOpenSeconds();
        Cache::put($key, [
            'state' => 'open',
            'open_until' => $openUntil,
            'failures' => $this->config->breakerFailureThreshold(),
            'reason' => $reason,
        ], $this->config->breakerOpenSeconds() * 2);

        $this->audit->record('svrs_nfce.breaker.open', 'SUCCESS', null, [
            'scope' => $key,
            'reason' => $reason,
            'open_until' => $openUntil,
        ], $userId, $officeId);
    }

    private function incrementTowardTrip(string $key, string $reason, ?int $userId, ?int $officeId): void
    {
        $data = Cache::get($key, ['state' => 'closed', 'open_until' => null, 'failures' => 0]);
        if (($data['state'] ?? 'closed') === 'open') {
            return;
        }
        $failures = (int) ($data['failures'] ?? 0) + 1;
        if ($failures >= $this->config->breakerFailureThreshold()) {
            $this->trip($key, $reason, $userId, $officeId);

            return;
        }
        Cache::put($key, [
            'state' => 'closed',
            'open_until' => null,
            'failures' => $failures,
        ], $this->config->breakerOpenSeconds() * 2);
    }

    private function close(string $key, string $reason): void
    {
        Cache::put($key, [
            'state' => 'closed',
            'open_until' => null,
            'failures' => 0,
            'closed_reason' => $reason,
        ], $this->config->breakerOpenSeconds() * 2);
    }
}
