<?php

namespace App\Services\Communication\Pairing;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final readonly class PairingStateStore
{
    public function __construct(private Repository $cache) {}

    /** @param array<string, mixed> $payload */
    public function reserve(int $inboxId, array $payload): bool
    {
        $current = $this->get($inboxId);
        if ($current !== null && $this->isActive($current)) {
            return false;
        }
        if ($current !== null) {
            $this->forget($inboxId);
        }

        [$normalized, $seconds] = $this->normalize($payload);

        return $this->cache->add(
            $this->key($inboxId),
            Crypt::encryptString(json_encode($normalized, JSON_THROW_ON_ERROR)),
            $seconds,
        );
    }

    /** @param array<string, mixed> $payload */
    public function put(int $inboxId, array $payload): void
    {
        [$normalized, $seconds] = $this->normalize($payload);
        $this->cache->put(
            $this->key($inboxId),
            Crypt::encryptString(json_encode($normalized, JSON_THROW_ON_ERROR)),
            $seconds,
        );
    }

    /** @return array<string, mixed>|null */
    public function get(int $inboxId): ?array
    {
        $encrypted = $this->cache->get($this->key($inboxId));
        if (! is_string($encrypted)) {
            return null;
        }
        try {
            $payload = json_decode(Crypt::decryptString($encrypted), true, 16, JSON_THROW_ON_ERROR);

            return is_array($payload) ? $payload : null;
        } catch (Throwable) {
            $this->forget($inboxId);

            return null;
        }
    }

    public function forget(int $inboxId): void
    {
        $this->cache->forget($this->key($inboxId));
    }

    private function key(int $inboxId): string
    {
        return 'communication:pairing:inbox:'.$inboxId;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0:array<string,mixed>,1:int}
     */
    private function normalize(array $payload): array
    {
        $expiresAt = rescue(
            static fn () => isset($payload['expires_at']) ? now()->parse($payload['expires_at']) : now()->addMinutes(2),
            now()->addMinutes(2),
            report: false,
        );
        $seconds = max(1, min(300, (int) now()->diffInSeconds($expiresAt, false)));
        $payload['expires_at'] = $expiresAt->toIso8601String();

        return [$payload, $seconds];
    }

    /** @param array<string, mixed> $payload */
    private function isActive(array $payload): bool
    {
        $event = strtoupper(trim((string) ($payload['event'] ?? '')));
        if (! in_array($event, [
            'PENDING', 'CODE', 'QR', 'QR_AVAILABLE', 'PHONE-CODE',
            'PASSKEY_REQUIRED', 'PASSKEY_CONFIRMATION_REQUIRED',
        ], true)) {
            return false;
        }

        return rescue(
            static fn () => isset($payload['expires_at']) && now()->parse($payload['expires_at'])->isFuture(),
            false,
            report: false,
        );
    }
}
