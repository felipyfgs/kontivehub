<?php

namespace Tests\Unit\Vault;

use App\Services\Vault\EnvelopeCrypto;
use RuntimeException;
use Tests\TestCase;

final class EnvelopeCryptoTest extends TestCase
{
    public function test_seal_open_round_trip_recovers_plaintext(): void
    {
        $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $crypto = new EnvelopeCrypto($key, 1);
        $metadata = ['purpose' => 'unit-test', 'office_id' => 42];

        $envelope = $crypto->seal('segredo-fiscal', $metadata);

        $this->assertSame(1, $envelope['key_version']);
        $this->assertSame('segredo-fiscal', $crypto->open($envelope, $metadata));
    }

    public function test_from_config_uses_phpunit_vault_key(): void
    {
        $crypto = EnvelopeCrypto::fromConfig();
        $metadata = ['purpose' => 'from-config'];

        $envelope = $crypto->seal('payload', $metadata);

        $this->assertSame('payload', $crypto->open($envelope, $metadata));
        $this->assertContains(1, $crypto->availableKeyVersions());
    }

    public function test_invalid_master_key_length_is_fail_closed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('VAULT_MASTER_KEY deve decodificar para 32 bytes.');

        new EnvelopeCrypto('curta', 1);
    }

    public function test_tampered_ciphertext_fails_without_leaking_plaintext(): void
    {
        $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $crypto = new EnvelopeCrypto($key, 1);
        $metadata = ['purpose' => 'tamper'];
        $envelope = $crypto->seal('nao-vazar', $metadata);
        $envelope['ciphertext'] = str_repeat("\0", strlen($envelope['ciphertext']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Falha ao');

        $crypto->open($envelope, $metadata);
    }

    public function test_wrong_key_cannot_open_envelope(): void
    {
        $sealKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $otherKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $sealed = (new EnvelopeCrypto($sealKey, 1))->seal('segredo', ['purpose' => 'wrong-key']);

        $this->expectException(RuntimeException::class);

        (new EnvelopeCrypto($otherKey, 1))->open($sealed, ['purpose' => 'wrong-key']);
    }
}
