<?php

namespace Tests\Unit\Communication;

use App\Enums\Communication\SignatureVerificationResult;
use App\Services\Communication\Security\HmacCanonicalizer;
use App\Services\Communication\Security\HmacSigner;
use App\Services\Communication\Security\HmacVerifier;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use RuntimeException;
use Tests\TestCase;

class CommunicationHmacTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('communication.hmac', [
            'current_key_id' => 'laravel-v2',
            'current_secret' => 'current-test-secret',
            'previous_key_id' => 'laravel-v1',
            'previous_secret' => 'previous-test-secret',
            'window_seconds' => 300,
            'nonce_ttl_seconds' => 600,
        ]);
    }

    public function test_signs_and_accepts_the_canonical_contract_once(): void
    {
        $timestamp = 1_785_000_000;
        $nonce = '3de95ee4-20a8-48c1-a257-944f458f6915';
        $body = '{"command_id":"command-0001"}';
        $headers = app(HmacSigner::class)->headers(
            'post',
            '/internal/v1/commands',
            $body,
            $timestamp,
            $nonce,
        );

        $verifier = $this->verifier();
        $this->assertSame(
            SignatureVerificationResult::Valid,
            $verifier->verify('POST', '/internal/v1/commands', $body, $headers, $timestamp + 30),
        );
        $this->assertSame(
            SignatureVerificationResult::Replay,
            $verifier->verify('POST', '/internal/v1/commands', $body, $headers, $timestamp + 31),
        );
    }

    public function test_rejects_stale_timestamp_before_claiming_nonce(): void
    {
        $timestamp = 1_785_000_000;
        $headers = app(HmacSigner::class)->headers(
            'post',
            '/internal/v1/commands',
            '{}',
            $timestamp,
            '7f55c739-bb97-474b-a3a9-778035204f68',
        );

        $this->assertSame(
            SignatureVerificationResult::StaleTimestamp,
            $this->verifier()->verify('POST', '/internal/v1/commands', '{}', $headers, $timestamp + 301),
        );
    }

    public function test_rejects_body_tampering_and_accepts_previous_rotation_key(): void
    {
        $timestamp = 1_785_000_000;
        $nonce = '724a0612-4832-4696-87e8-0f45d0c5d8e2';
        $canonical = app(HmacCanonicalizer::class)->canonical(
            'POST',
            '/api/internal/v1/whatsapp/events',
            '{}',
            $timestamp,
            $nonce,
        );
        $headers = [
            'X-Communication-Key-Id' => 'laravel-v1',
            'X-Communication-Timestamp' => (string) $timestamp,
            'X-Communication-Nonce' => $nonce,
            'X-Communication-Signature' => 'v1='.hash_hmac('sha256', $canonical, 'previous-test-secret'),
        ];

        $this->assertSame(
            SignatureVerificationResult::InvalidSignature,
            $this->verifier()->verify(
                'POST',
                '/api/internal/v1/whatsapp/events',
                '{"tampered":true}',
                $headers,
                $timestamp,
            ),
        );
        $this->assertSame(
            SignatureVerificationResult::Valid,
            $this->verifier()->verify(
                'POST',
                '/api/internal/v1/whatsapp/events',
                '{}',
                $headers,
                $timestamp,
            ),
        );
    }

    public function test_signer_fails_closed_without_secret(): void
    {
        config()->set('communication.hmac.current_secret', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('WAZYNC_HMAC_KEY_ID e WAZYNC_HMAC_SECRET devem ser configurados.');
        app(HmacSigner::class)->headers('POST', '/internal/v1/commands');
    }

    private function verifier(): HmacVerifier
    {
        return new HmacVerifier(
            app(HmacCanonicalizer::class),
            app(CacheRepository::class),
        );
    }
}
