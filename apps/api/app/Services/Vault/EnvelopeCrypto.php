<?php

namespace App\Services\Vault;

use RuntimeException;

/**
 * Envelope: DEK aleatória + XChaCha20-Poly1305; DEK embrulhada pela master key versionada.
 *
 * Keyring: a versão atual sela objetos novos; versões anteriores só leem (rewrap/migração).
 * key_version no envelope é a versão criptográfica da master key — distinta do AAD de negócio.
 */
final class EnvelopeCrypto
{
    /** @var array<int, string> version => 32-byte master key */
    private readonly array $keyring;

    private readonly int $currentVersion;

    /**
     * @param  array<int, string>  $keyring  map version => raw 32-byte key (current + previous)
     */
    public function __construct(
        string $masterKeyBinary,
        int $keyVersion = 1,
        array $previousKeys = [],
    ) {
        if (strlen($masterKeyBinary) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('VAULT_MASTER_KEY deve decodificar para 32 bytes.');
        }

        $ring = [$keyVersion => $masterKeyBinary];
        foreach ($previousKeys as $ver => $key) {
            $v = (int) $ver;
            if ($v === $keyVersion) {
                continue;
            }
            if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new RuntimeException("VAULT keyring: chave da versão {$v} inválida (32 bytes).");
            }
            $ring[$v] = $key;
        }

        ksort($ring);
        $this->keyring = $ring;
        $this->currentVersion = $keyVersion;
    }

    public static function fromConfig(): self
    {
        $encoded = (string) config('vault.master_key', '');
        $version = (int) config('vault.master_key_version', 1);

        if ($encoded === '') {
            throw new RuntimeException('VAULT_MASTER_KEY não configurada.');
        }

        $binary = base64_decode($encoded, true);
        if ($binary === false) {
            throw new RuntimeException('VAULT_MASTER_KEY inválida (base64).');
        }

        $previous = [];
        $rawPrevious = config('vault.previous_master_keys', []);
        if (is_array($rawPrevious)) {
            foreach ($rawPrevious as $ver => $b64) {
                if (! is_string($b64) || $b64 === '') {
                    continue;
                }
                $decoded = base64_decode($b64, true);
                if ($decoded === false) {
                    throw new RuntimeException("VAULT_PREVIOUS_MASTER_KEYS: versão {$ver} inválida (base64).");
                }
                $previous[(int) $ver] = $decoded;
            }
        }

        return new self($binary, $version, $previous);
    }

    public function currentKeyVersion(): int
    {
        return $this->currentVersion;
    }

    /**
     * @return list<int>
     */
    public function availableKeyVersions(): array
    {
        return array_map('intval', array_keys($this->keyring));
    }

    /**
     * @param  array<string, scalar|null>  $metadata  AAD de negócio (purpose, office_id, …) — NÃO é key_version
     * @return array{ciphertext: string, wrapped_dek: string, nonce: string, wrap_nonce: string, key_version: int}
     */
    public function seal(string $plaintext, array $metadata = []): array
    {
        $dek = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $aad = $this->aad($metadata);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $aad,
            $nonce,
            $dek
        );

        $currentKey = $this->keyring[$this->currentVersion]
            ?? throw new RuntimeException('Chave mestra atual ausente no keyring.');

        $wrapNonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $wrappedDek = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $dek,
            $this->wrapAad($this->currentVersion),
            $wrapNonce,
            $currentKey
        );

        sodium_memzero($dek);

        return [
            'ciphertext' => $ciphertext,
            'wrapped_dek' => $wrappedDek,
            'nonce' => $nonce,
            'wrap_nonce' => $wrapNonce,
            'key_version' => $this->currentVersion,
        ];
    }

    /**
     * @param  array{ciphertext: string, wrapped_dek: string, nonce: string, wrap_nonce: string, key_version: int}  $envelope
     * @param  array<string, scalar|null>  $metadata
     */
    public function open(array $envelope, array $metadata = []): string
    {
        $keyVersion = (int) ($envelope['key_version'] ?? 0);
        $master = $this->keyring[$keyVersion] ?? null;
        if ($master === null) {
            throw new RuntimeException(
                "Falha ao desembrulhar DEK: key_version={$keyVersion} ausente no keyring (disponíveis: "
                .implode(',', $this->availableKeyVersions()).').'
            );
        }

        $dek = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $envelope['wrapped_dek'],
            $this->wrapAad($keyVersion),
            $envelope['wrap_nonce'],
            $master
        );

        if ($dek === false) {
            throw new RuntimeException('Falha ao desembrulhar DEK (chave mestra ou adulteração).');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $envelope['ciphertext'],
            $this->aad($metadata),
            $envelope['nonce'],
            $dek
        );

        sodium_memzero($dek);

        if ($plaintext === false) {
            throw new RuntimeException('Falha ao descriptografar objeto (AAD/metadados ou adulteração).');
        }

        return $plaintext;
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    private function aad(array $metadata): string
    {
        // key_version NÃO entra no AAD de negócio — evita confusão com versão criptográfica.
        unset($metadata['key_version'], $metadata['crypto_key_version']);
        ksort($metadata);

        return json_encode($metadata, JSON_THROW_ON_ERROR);
    }

    private function wrapAad(int $version): string
    {
        return 'vault-dek-v'.$version;
    }
}
