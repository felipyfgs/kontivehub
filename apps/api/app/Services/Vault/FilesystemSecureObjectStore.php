<?php

namespace App\Services\Vault;

use App\Contracts\SecureObjectStore;
use App\Models\VaultObjectJournalEntry;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class FilesystemSecureObjectStore implements SecureObjectStore
{
    public function __construct(
        private readonly EnvelopeCrypto $crypto,
        private readonly string $root,
    ) {
        if (! is_dir($this->root) && ! mkdir($this->root, 0700, true) && ! is_dir($this->root)) {
            throw new RuntimeException('Não foi possível criar o diretório do cofre.');
        }
    }

    public function put(string $plaintext, array $metadata = []): string
    {
        $id = (string) Str::ulid();
        $envelope = $this->crypto->seal($plaintext, $metadata);

        $payload = [
            'v' => 1,
            'key_version' => $envelope['key_version'],
            'nonce' => base64_encode($envelope['nonce']),
            'wrap_nonce' => base64_encode($envelope['wrap_nonce']),
            'wrapped_dek' => base64_encode($envelope['wrapped_dek']),
            'ciphertext' => base64_encode($envelope['ciphertext']),
        ];

        $path = $this->path($id);
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar o diretório do objeto.');
        }

        if (file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Falha ao gravar objeto no cofre.');
        }

        chmod($path, 0600);

        $this->journalPut($id, $metadata, (int) $envelope['key_version'], $plaintext);

        return $id;
    }

    public function get(string $objectId, array $metadata = []): string
    {
        $path = $this->path($objectId);
        if (! is_file($path)) {
            throw new RuntimeException('Objeto não encontrado no cofre.');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Falha ao ler objeto do cofre.');
        }

        /** @var array{v:int,key_version:int,nonce:string,wrap_nonce:string,wrapped_dek:string,ciphertext:string} $payload */
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $ciphertext = base64_decode((string) ($payload['ciphertext'] ?? ''), true);
        $wrappedDek = base64_decode((string) ($payload['wrapped_dek'] ?? ''), true);
        $nonce = base64_decode((string) ($payload['nonce'] ?? ''), true);
        $wrapNonce = base64_decode((string) ($payload['wrap_nonce'] ?? ''), true);

        if ($ciphertext === false || $wrappedDek === false || $nonce === false || $wrapNonce === false) {
            throw new RuntimeException('Envelope do cofre corrompido (base64).');
        }

        return $this->crypto->open([
            'ciphertext' => $ciphertext,
            'wrapped_dek' => $wrappedDek,
            'nonce' => $nonce,
            'wrap_nonce' => $wrapNonce,
            'key_version' => (int) $payload['key_version'],
        ], $metadata);
    }

    public function delete(string $objectId): void
    {
        $path = $this->path($objectId);
        if (is_file($path)) {
            $size = filesize($path) ?: 0;
            if ($size > 0) {
                file_put_contents($path, random_bytes(min($size, 4096)));
            }
            unlink($path);
        }

        $this->journalMarkDeleted($objectId);
    }

    public function exists(string $objectId): bool
    {
        return is_file($this->path($objectId));
    }

    /**
     * Re-cifra um objeto com a chave mestra atual (idempotente se já na versão atual).
     *
     * @param  array<string, scalar|null>  $metadata
     * @return array{object_id: string, from_version: int, to_version: int, rewritten: bool}
     */
    public function rewrap(string $objectId, array $metadata = [], bool $dryRun = false): array
    {
        $path = $this->path($objectId);
        if (! is_file($path)) {
            throw new RuntimeException('Objeto não encontrado no cofre.');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Falha ao ler objeto do cofre.');
        }

        /** @var array{v:int,key_version:int,nonce:string,wrap_nonce:string,wrapped_dek:string,ciphertext:string} $payload */
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $fromVersion = (int) ($payload['key_version'] ?? 0);
        $toVersion = $this->crypto->currentKeyVersion();

        if ($fromVersion === $toVersion) {
            return [
                'object_id' => $objectId,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'rewritten' => false,
            ];
        }

        $plaintext = $this->get($objectId, $metadata);
        if ($dryRun) {
            // Verifica que open funciona e que re-seal seria possível sem gravar.
            $probe = $this->crypto->seal($plaintext, $metadata);
            unset($plaintext, $probe);

            return [
                'object_id' => $objectId,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'rewritten' => false,
            ];
        }

        $envelope = $this->crypto->seal($plaintext, $metadata);
        unset($plaintext);

        $newPayload = [
            'v' => 1,
            'key_version' => $envelope['key_version'],
            'nonce' => base64_encode($envelope['nonce']),
            'wrap_nonce' => base64_encode($envelope['wrap_nonce']),
            'wrapped_dek' => base64_encode($envelope['wrapped_dek']),
            'ciphertext' => base64_encode($envelope['ciphertext']),
        ];

        $tmp = $path.'.rewrap-tmp';
        if (file_put_contents($tmp, json_encode($newPayload, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Falha ao gravar envelope rewrap temporário.');
        }
        chmod($tmp, 0600);

        // Verificação pós-escrita antes de substituir.
        $verifyRaw = file_get_contents($tmp);
        if ($verifyRaw === false) {
            @unlink($tmp);
            throw new RuntimeException('Falha ao verificar envelope rewrap.');
        }
        /** @var array{key_version:int,nonce:string,wrap_nonce:string,wrapped_dek:string,ciphertext:string} $verifyPayload */
        $verifyPayload = json_decode($verifyRaw, true, 512, JSON_THROW_ON_ERROR);
        $this->crypto->open([
            'ciphertext' => base64_decode((string) $verifyPayload['ciphertext'], true) ?: '',
            'wrapped_dek' => base64_decode((string) $verifyPayload['wrapped_dek'], true) ?: '',
            'nonce' => base64_decode((string) $verifyPayload['nonce'], true) ?: '',
            'wrap_nonce' => base64_decode((string) $verifyPayload['wrap_nonce'], true) ?: '',
            'key_version' => (int) $verifyPayload['key_version'],
        ], $metadata);

        if (! rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Falha ao promover envelope rewrap.');
        }
        chmod($path, 0600);

        $this->journalRewrap($objectId, $toVersion);

        return [
            'object_id' => $objectId,
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'rewritten' => true,
        ];
    }

    /**
     * Lista object IDs no disco do vault.
     *
     * @return list<string>
     */
    public function listObjectIds(): array
    {
        if (! is_dir($this->root)) {
            return [];
        }

        $ids = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $name = $file->getBasename('.json');
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $name)) {
                $ids[] = strtoupper($name);
            }
        }
        sort($ids);

        return $ids;
    }

    public function cryptoKeyVersionOf(string $objectId): int
    {
        $path = $this->path($objectId);
        if (! is_file($path)) {
            throw new RuntimeException('Objeto não encontrado no cofre.');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Falha ao ler objeto do cofre.');
        }
        /** @var array{key_version?: int} $payload */
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return (int) ($payload['key_version'] ?? 0);
    }

    private function path(string $objectId): string
    {
        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $objectId)) {
            throw new RuntimeException('Identificador de objeto inválido.');
        }

        $prefix = strtolower(substr($objectId, 0, 2));

        return rtrim($this->root, '/').'/'.$prefix.'/'.$objectId.'.json';
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    private function journalPut(string $id, array $metadata, int $keyVersion, string $plaintext): void
    {
        if (! $this->journalAvailable()) {
            return;
        }

        try {
            VaultObjectJournalEntry::query()->updateOrCreate(
                ['object_id' => $id],
                [
                    'purpose' => (string) ($metadata['purpose'] ?? 'UNKNOWN'),
                    'crypto_key_version' => $keyVersion,
                    'rewrap_status' => 'CURRENT',
                    'content_sha256' => hash('sha256', $plaintext),
                    'office_id' => isset($metadata['office_id']) ? (int) $metadata['office_id'] : null,
                    'metadata' => [
                        'aad_keys' => array_keys($metadata),
                    ],
                ]
            );
        } catch (Throwable) {
            // Journal é best-effort; vault put não deve falhar por journal.
        }
    }

    private function journalRewrap(string $id, int $keyVersion): void
    {
        if (! $this->journalAvailable()) {
            return;
        }

        try {
            VaultObjectJournalEntry::query()->where('object_id', $id)->update([
                'crypto_key_version' => $keyVersion,
                'rewrap_status' => 'CURRENT',
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // best-effort
        }
    }

    private function journalMarkDeleted(string $id): void
    {
        if (! $this->journalAvailable()) {
            return;
        }

        try {
            VaultObjectJournalEntry::query()->where('object_id', $id)->update([
                'deleted_at' => now(),
                'rewrap_status' => 'DELETED',
            ]);
        } catch (Throwable) {
            // best-effort
        }
    }

    private function journalAvailable(): bool
    {
        try {
            return Schema::hasTable('vault_object_journal');
        } catch (Throwable) {
            return false;
        }
    }
}
