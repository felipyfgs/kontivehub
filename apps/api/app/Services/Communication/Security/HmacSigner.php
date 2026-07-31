<?php

namespace App\Services\Communication\Security;

use Illuminate\Support\Str;
use RuntimeException;

final readonly class HmacSigner
{
    public function __construct(private HmacCanonicalizer $canonicalizer) {}

    /** @return array<string, string> */
    public function headers(
        string $method,
        string $path,
        string $body = '',
        ?int $timestamp = null,
        ?string $nonce = null,
    ): array {
        $keyId = trim((string) config('communication.hmac.current_key_id'));
        $secret = (string) config('communication.hmac.current_secret');

        if ($keyId === '' || $secret === '') {
            throw new RuntimeException('WAZYNC_HMAC_KEY_ID e WAZYNC_HMAC_SECRET devem ser configurados.');
        }

        $timestamp ??= now()->getTimestamp();
        $nonce ??= (string) Str::uuid();
        $canonical = $this->canonicalizer->canonical($method, $path, $body, $timestamp, $nonce);

        return [
            'X-Communication-Key-Id' => $keyId,
            'X-Communication-Timestamp' => (string) $timestamp,
            'X-Communication-Nonce' => $nonce,
            'X-Communication-Signature' => 'v1='.hash_hmac('sha256', $canonical, $secret),
        ];
    }
}
