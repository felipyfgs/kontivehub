<?php

namespace App\DTO\Esocial;

use InvalidArgumentException;

final readonly class EsocialBxReadiness
{
    /** @param list<array{code:string,message:string}> $blockers */
    public function __construct(
        public bool $ready,
        public string $driver,
        public string $environment,
        public array $blockers,
        public int $dailyLimit,
        public int $locallyConsumed,
        public int $locallyRemaining,
        public ?string $credentialFingerprint = null,
        public ?string $credentialExpiresAt = null,
    ) {
        if ($this->dailyLimit < 1
            || $this->dailyLimit > 10
            || $this->locallyConsumed < 0
            || $this->locallyRemaining < 0
            || $this->locallyConsumed + $this->locallyRemaining !== $this->dailyLimit) {
            throw new InvalidArgumentException('Quota de readiness eSocial BX inválida.');
        }
        foreach ($this->blockers as $blocker) {
            if (preg_match('/^ESOCIAL_BX_[A-Z0-9_]+$/', $blocker['code'] ?? '') !== 1
                || trim((string) ($blocker['message'] ?? '')) === ''
                || preg_match('/[\r\n]/', (string) ($blocker['message'] ?? '')) === 1) {
                throw new InvalidArgumentException('Blocker de readiness eSocial BX inválido.');
            }
        }
        if ($this->credentialFingerprint !== null
            && preg_match('/^[a-f0-9]{64}$/i', $this->credentialFingerprint) !== 1) {
            throw new InvalidArgumentException('Fingerprint de readiness eSocial BX inválido.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'driver' => $this->driver,
            'environment' => $this->environment,
            'blockers' => $this->blockers,
            'daily_limit' => $this->dailyLimit,
            'locally_consumed' => $this->locallyConsumed,
            'locally_remaining' => $this->locallyRemaining,
            'credential' => $this->credentialFingerprint === null ? null : [
                'fingerprint_suffix' => substr($this->credentialFingerprint, -12),
                'expires_at' => $this->credentialExpiresAt,
            ],
            'blocked_days' => config('fgts_esocial.official_bx.blocked_days', range(1, 7)),
            'quota_scope' => 'employer/environment/day; local count is conservative',
        ];
    }
}
