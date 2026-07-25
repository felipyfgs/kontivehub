<?php

namespace App\Services\Communication\Security;

use App\Enums\Communication\SignatureVerificationResult;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use InvalidArgumentException;

final readonly class CommunicationHmacVerifier
{
    public function __construct(
        private CommunicationHmacCanonicalizer $canonicalizer,
        private CacheRepository $cache,
    ) {}

    /** @param array<string, string|string[]> $headers */
    public function verify(
        string $method,
        string $path,
        string $body,
        array $headers,
        ?int $now = null,
    ): SignatureVerificationResult {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $keyId = $this->header($headers, 'x-communication-key-id');
        $timestampValue = $this->header($headers, 'x-communication-timestamp');
        $nonce = $this->header($headers, 'x-communication-nonce');
        $signature = $this->header($headers, 'x-communication-signature');

        if ($keyId === '' || $timestampValue === '' || $nonce === '' || $signature === '') {
            return SignatureVerificationResult::MissingHeaders;
        }

        if (! preg_match('/^[0-9]{10}$/', $timestampValue)) {
            return SignatureVerificationResult::InvalidTimestamp;
        }

        $timestamp = (int) $timestampValue;
        $now ??= now()->getTimestamp();
        if (abs($now - $timestamp) > (int) config('communication.hmac.window_seconds', 300)) {
            return SignatureVerificationResult::StaleTimestamp;
        }

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/', $nonce)) {
            return SignatureVerificationResult::InvalidNonce;
        }

        $secret = $this->secretFor($keyId);
        if ($secret === null) {
            return SignatureVerificationResult::UnknownKey;
        }

        try {
            $canonical = $this->canonicalizer->canonical($method, $path, $body, $timestamp, $nonce);
        } catch (InvalidArgumentException) {
            return SignatureVerificationResult::InvalidNonce;
        }

        $expected = 'v1='.hash_hmac('sha256', $canonical, $secret);
        if (! hash_equals($expected, $signature)) {
            return SignatureVerificationResult::InvalidSignature;
        }

        $ttl = max(
            (int) config('communication.hmac.window_seconds', 300),
            (int) config('communication.hmac.nonce_ttl_seconds', 600),
        );
        $claimed = $this->cache->add(
            'communication:hmac:nonce:'.hash('sha256', $keyId.'|'.$nonce),
            true,
            $ttl,
        );

        return $claimed ? SignatureVerificationResult::Valid : SignatureVerificationResult::Replay;
    }

    /** @param array<string, string|string[]> $headers */
    private function header(array $headers, string $key): string
    {
        $value = $headers[$key] ?? '';

        return trim(is_array($value) ? (string) ($value[0] ?? '') : (string) $value);
    }

    private function secretFor(string $keyId): ?string
    {
        $pairs = [
            (string) config('communication.hmac.current_key_id') => (string) config('communication.hmac.current_secret'),
            (string) config('communication.hmac.previous_key_id') => (string) config('communication.hmac.previous_secret'),
        ];
        $secret = $pairs[$keyId] ?? '';

        return $keyId !== '' && $secret !== '' ? $secret : null;
    }
}
