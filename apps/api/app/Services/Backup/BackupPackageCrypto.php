<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Cifra/autentica o pacote unificado de backup (DB + vault + private) com chave externa.
 * A chave NÃO é a VAULT_MASTER_KEY e NUNCA entra no artefato.
 *
 * Formato binário v1:
 *   magic(8) | version(1) | nonce(24) | pt_len_u64_be(8) | ciphertext+tag
 * AAD: "nfse-backup-package-v1|len={pt_len}"
 */
final class BackupPackageCrypto
{
    public const MAGIC = 'NFSEBKP1';

    public const FORMAT_VERSION = 1;

    public function __construct(
        private readonly string $packageKeyBinary,
    ) {
        if (strlen($this->packageKeyBinary) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('BACKUP_PACKAGE_KEY deve decodificar para 32 bytes.');
        }
    }

    public static function fromConfig(): ?self
    {
        $encoded = (string) config('backup.package_key', '');
        if ($encoded === '') {
            return null;
        }

        return self::fromBase64($encoded);
    }

    public static function fromBase64(string $encoded): self
    {
        $binary = base64_decode($encoded, true);
        if ($binary === false) {
            throw new RuntimeException('Chave de pacote inválida (base64).');
        }

        return new self($binary);
    }

    public function seal(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ptLen = strlen($plaintext);
        $aad = $this->aad($ptLen);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $aad,
            $nonce,
            $this->packageKeyBinary,
        );

        return self::MAGIC
            .chr(self::FORMAT_VERSION)
            .$nonce
            .pack('J', $ptLen)
            .$ciphertext;
    }

    public function open(string $package): string
    {
        $min = 8 + 1 + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + 8 + 16;
        if (strlen($package) < $min) {
            throw new RuntimeException('Pacote cifrado de backup truncado.');
        }

        $magic = substr($package, 0, 8);
        if (! hash_equals(self::MAGIC, $magic)) {
            throw new RuntimeException('Magic de pacote de backup inválido.');
        }

        $version = ord($package[8]);
        if ($version !== self::FORMAT_VERSION) {
            throw new RuntimeException("Versão de pacote de backup incompatível: {$version}.");
        }

        $nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $nonce = substr($package, 9, $nonceLen);
        $ptLenPacked = substr($package, 9 + $nonceLen, 8);
        $ciphertext = substr($package, 9 + $nonceLen + 8);

        $unpacked = unpack('J', $ptLenPacked);
        $ptLen = is_array($unpacked) ? (int) ($unpacked[1] ?? 0) : 0;
        if ($ptLen < 0 || $ptLen > 50 * 1024 * 1024 * 1024) {
            throw new RuntimeException('Tamanho de plaintext do pacote inválido.');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $this->aad($ptLen),
            $nonce,
            $this->packageKeyBinary,
        );

        if ($plaintext === false) {
            throw new RuntimeException(
                'Falha ao descriptografar pacote de backup (chave incorreta ou adulteração).'
            );
        }

        if (strlen($plaintext) !== $ptLen) {
            throw new RuntimeException('Tamanho do plaintext do pacote não confere.');
        }

        return $plaintext;
    }

    public static function isSealedPackage(string $bytes): bool
    {
        return strlen($bytes) >= 8 && hash_equals(self::MAGIC, substr($bytes, 0, 8));
    }

    private function aad(int $plaintextLength): string
    {
        return 'nfse-backup-package-v1|len='.$plaintextLength;
    }
}
