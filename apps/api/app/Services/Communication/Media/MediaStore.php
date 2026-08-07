<?php

namespace App\Services\Communication\Media;

use App\Services\Vault\EnvelopeCrypto;
use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

final readonly class MediaStore
{
    private const MAGIC = 'FHCM1';

    private const CHUNK_BYTES = 65_536;

    public function __construct(
        private EnvelopeCrypto $crypto,
        private Filesystem $disk,
    ) {}

    /**
     * @param  resource|StreamInterface  $source
     * @param  array<string, scalar|null>  $metadata
     * @return array{object_id:string,size_bytes:int,sha256:string}
     */
    public function putStream(mixed $source, array $metadata): array
    {
        if (! is_resource($source) && ! $source instanceof StreamInterface) {
            throw new RuntimeException('Stream de mídia inválido.');
        }

        $objectId = (string) str()->ulid();
        $path = $this->path($objectId);
        $output = tmpfile();
        if (! is_resource($output)) {
            throw new RuntimeException('Não foi possível criar o spool cifrado de mídia.');
        }

        $streamKey = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($streamKey);
        $aad = [...$metadata, 'purpose' => 'COMMUNICATION_MEDIA', 'object_id' => $objectId];
        $keyEnvelope = $this->crypto->seal($streamKey, $aad);
        sodium_memzero($streamKey);

        $header = json_encode([
            'v' => 1,
            'stream_header' => base64_encode($streamHeader),
            'key_envelope' => [
                'key_version' => $keyEnvelope['key_version'],
                'nonce' => base64_encode($keyEnvelope['nonce']),
                'wrap_nonce' => base64_encode($keyEnvelope['wrap_nonce']),
                'wrapped_dek' => base64_encode($keyEnvelope['wrapped_dek']),
                'ciphertext' => base64_encode($keyEnvelope['ciphertext']),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $size = 0;
        $index = 0;
        $hasher = hash_init('sha256');
        $maximum = max(1, (int) config('communication.media.max_bytes', 20_971_520));

        try {
            $this->write($output, self::MAGIC);
            $this->write($output, pack('N', strlen($header)));
            $this->write($output, $header);

            while (! $this->sourceEof($source)) {
                $chunk = $this->sourceRead($source, self::CHUNK_BYTES);
                if ($chunk === '') {
                    if ($this->sourceEof($source)) {
                        break;
                    }

                    continue;
                }
                $size += strlen($chunk);
                if ($size > $maximum) {
                    throw new RuntimeException('Mídia excede o limite configurado.');
                }
                hash_update($hasher, $chunk);
                $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $chunk,
                    $objectId.':'.$index,
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                );
                $this->writeRecord($output, $ciphertext);
                $index++;
            }

            $final = sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                '',
                $objectId.':'.$index,
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
            );
            $this->writeRecord($output, $final);
            fflush($output);
            if (function_exists('fsync')) {
                fsync($output);
            }
            if (fseek($output, 0) !== 0 || ! $this->disk->writeStream($path, $output, ['visibility' => 'private'])) {
                throw new RuntimeException('Não foi possível armazenar a mídia cifrada.');
            }

            return [
                'object_id' => $objectId,
                'size_bytes' => $size,
                'sha256' => hash_final($hasher),
            ];
        } catch (Throwable $error) {
            if ($this->disk->exists($path)) {
                $this->disk->delete($path);
            }
            throw $error;
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     * @return Generator<int, string>
     */
    public function readChunks(string $objectId, array $metadata): Generator
    {
        $input = $this->disk->readStream($this->path($objectId));
        if (! is_resource($input)) {
            throw new RuntimeException('Mídia não encontrada.');
        }

        try {
            if ($this->readExact($input, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Envelope de mídia inválido.');
            }
            $length = unpack('Nlength', $this->readExact($input, 4))['length'] ?? 0;
            if ($length < 1 || $length > 65_536) {
                throw new RuntimeException('Cabeçalho de mídia inválido.');
            }
            $header = json_decode($this->readExact($input, $length), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($header) || (int) ($header['v'] ?? 0) !== 1 || ! is_array($header['key_envelope'] ?? null)) {
                throw new RuntimeException('Versão de mídia inválida.');
            }
            $envelope = $header['key_envelope'];
            $decode = static function (mixed $value): string {
                $decoded = is_string($value) ? base64_decode($value, true) : false;
                if ($decoded === false) {
                    throw new RuntimeException('Envelope de mídia corrompido.');
                }

                return $decoded;
            };
            $aad = [...$metadata, 'purpose' => 'COMMUNICATION_MEDIA', 'object_id' => $objectId];
            $streamKey = $this->crypto->open([
                'key_version' => (int) ($envelope['key_version'] ?? 0),
                'nonce' => $decode($envelope['nonce'] ?? null),
                'wrap_nonce' => $decode($envelope['wrap_nonce'] ?? null),
                'wrapped_dek' => $decode($envelope['wrapped_dek'] ?? null),
                'ciphertext' => $decode($envelope['ciphertext'] ?? null),
            ], $aad);
            $streamHeader = $decode($header['stream_header'] ?? null);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $streamKey);
            sodium_memzero($streamKey);
            $index = 0;
            $finalSeen = false;

            while (! feof($input)) {
                $rawLength = fread($input, 4);
                if ($rawLength === '' && feof($input)) {
                    break;
                }
                if (! is_string($rawLength) || strlen($rawLength) !== 4) {
                    throw new RuntimeException('Registro de mídia truncado.');
                }
                $recordLength = unpack('Nlength', $rawLength)['length'] ?? 0;
                if ($recordLength < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                    || $recordLength > self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('Registro de mídia inválido.');
                }
                $opened = sodium_crypto_secretstream_xchacha20poly1305_pull(
                    $state,
                    $this->readExact($input, $recordLength),
                    $objectId.':'.$index,
                );
                if ($opened === false) {
                    throw new RuntimeException('Falha de autenticação da mídia.');
                }
                [$plaintext, $tag] = $opened;
                if ($plaintext !== '') {
                    yield $plaintext;
                }
                $index++;
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $finalSeen = true;
                    if (! feof($input) && fread($input, 1) !== '') {
                        throw new RuntimeException('Dados extras após o fim da mídia.');
                    }
                    break;
                }
            }
            if (! $finalSeen) {
                throw new RuntimeException('Mídia sem marcador final autenticado.');
            }
        } finally {
            fclose($input);
        }
    }

    /** @param  array<string, scalar|null>  $metadata */
    public function readValidated(
        string $objectId,
        array $metadata,
        int $expectedSize,
        string $expectedSha256,
    ): string {
        $maximum = max(1, (int) config('communication.media.max_bytes', 20_971_520));
        if ($expectedSize < 0
            || $expectedSize > $maximum
            || preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1) {
            throw new RuntimeException('Descritor de mídia inválido.');
        }

        $contents = '';
        $size = 0;
        $hasher = hash_init('sha256');
        foreach ($this->readChunks($objectId, $metadata) as $chunk) {
            $size += strlen($chunk);
            if ($size > $expectedSize) {
                throw new RuntimeException('Tamanho de mídia divergente.');
            }
            $contents .= $chunk;
            hash_update($hasher, $chunk);
        }
        if ($size !== $expectedSize || ! hash_equals($expectedSha256, hash_final($hasher))) {
            throw new RuntimeException('Integridade da mídia inválida.');
        }

        return $contents;
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     * @return Generator<int, string>
     */
    public function readValidatedRange(
        string $objectId,
        array $metadata,
        int $start,
        int $end,
        int $expectedSize,
        string $expectedSha256,
    ): Generator {
        $maximum = max(1, (int) config('communication.media.max_bytes', 20_971_520));
        if ($start < 0
            || $end < $start
            || ($expectedSize > 0 && $end >= $expectedSize)
            || ($expectedSize === 0 && ($start !== 0 || $end !== -1))
            || $expectedSize < 0
            || $expectedSize > $maximum
            || preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1) {
            throw new RuntimeException('Descritor de mídia inválido.');
        }

        $range = tmpfile();
        if (! is_resource($range)) {
            throw new RuntimeException('Não foi possível criar o buffer da faixa de mídia.');
        }

        try {
            $size = 0;
            $offset = 0;
            $hasher = hash_init('sha256');
            foreach ($this->readChunks($objectId, $metadata) as $chunk) {
                $chunkSize = strlen($chunk);
                $size += $chunkSize;
                if ($size > $expectedSize) {
                    throw new RuntimeException('Tamanho de mídia divergente.');
                }
                hash_update($hasher, $chunk);

                $chunkEnd = $offset + $chunkSize - 1;
                if ($chunkEnd >= $start) {
                    $sliceStart = max(0, $start - $offset);
                    $sliceEnd = min($chunkSize - 1, $end - $offset);
                    if ($sliceEnd >= $sliceStart) {
                        $this->write($range, substr($chunk, $sliceStart, $sliceEnd - $sliceStart + 1));
                    }
                }
                $offset += $chunkSize;
            }
            if ($size !== $expectedSize || ! hash_equals($expectedSha256, hash_final($hasher))) {
                throw new RuntimeException('Integridade da mídia inválida.');
            }
            if (fseek($range, 0) !== 0) {
                throw new RuntimeException('Falha ao preparar a faixa de mídia.');
            }

            while (! feof($range)) {
                $chunk = fread($range, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('Falha ao ler a faixa de mídia.');
                }
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        } finally {
            fclose($range);
        }
    }

    public function delete(string $objectId): void
    {
        $path = $this->path($objectId);
        if ($this->disk->exists($path) && ! $this->disk->delete($path)) {
            throw new RuntimeException('Não foi possível excluir a mídia.');
        }
    }

    public function exists(string $objectId): bool
    {
        return $this->disk->exists($this->path($objectId));
    }

    /** @return Generator<int, string> Object ids older than the supplied cutoff; never returns paths. */
    public function oldObjectIds(\DateTimeInterface $cutoff, int $limit, ?string $afterObjectId = null): Generator
    {
        if ($limit <= 0) {
            return;
        }

        $remaining = min($limit, 500);
        $directories = [];
        foreach ($this->disk->directories() as $directory) {
            $directoryName = basename($directory);
            if (preg_match('/^[0-9a-hjkmnp-tv-z]{2}$/i', $directoryName)) {
                $directories[$directoryName] = $directory;
            }
        }
        ksort($directories, SORT_STRING);

        $afterDirectory = $afterObjectId !== null
            && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $afterObjectId) === 1
                ? strtolower(substr($afterObjectId, 0, 2))
                : null;

        foreach ($directories as $directoryName => $directory) {
            if ($remaining < 1) {
                break;
            }
            if ($afterDirectory !== null && strcmp(strtolower($directoryName), $afterDirectory) < 0) {
                continue;
            }
            $files = [];
            foreach ($this->disk->files($directory) as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if (pathinfo($file, PATHINFO_EXTENSION) === 'media'
                    && $this->disk->lastModified($file) <= $cutoff->getTimestamp()
                    && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $name)) {
                    if ($afterObjectId !== null && strcmp($name, $afterObjectId) <= 0) {
                        continue;
                    }
                    $files[$name] = true;
                    if (count($files) > $remaining) {
                        $largest = max(array_keys($files));
                        unset($files[$largest]);
                    }
                }
            }
            ksort($files, SORT_STRING);

            foreach (array_keys($files) as $name) {
                if ($remaining < 1) {
                    break 2;
                }
                $remaining--;
                yield $name;
            }
        }
    }

    private function path(string $objectId): string
    {
        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $objectId)) {
            throw new RuntimeException('Identificador de mídia inválido.');
        }

        return strtolower(substr($objectId, 0, 2)).'/'.$objectId.'.media';
    }

    /** @param resource $output */
    private function writeRecord($output, string $ciphertext): void
    {
        $this->write($output, pack('N', strlen($ciphertext)));
        $this->write($output, $ciphertext);
    }

    /** @param resource $output */
    private function write($output, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($output, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Falha ao gravar mídia cifrada.');
            }
            $offset += $written;
        }
    }

    /** @param resource $input */
    private function readExact($input, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length && ! feof($input)) {
            $chunk = fread($input, $length - strlen($bytes));
            if ($chunk === false) {
                throw new RuntimeException('Falha ao ler mídia cifrada.');
            }
            $bytes .= $chunk;
        }
        if (strlen($bytes) !== $length) {
            throw new RuntimeException('Mídia cifrada truncada.');
        }

        return $bytes;
    }

    /** @param resource|StreamInterface $source */
    private function sourceEof(mixed $source): bool
    {
        return $source instanceof StreamInterface ? $source->eof() : feof($source);
    }

    /** @param resource|StreamInterface $source */
    private function sourceRead(mixed $source, int $length): string
    {
        $chunk = $source instanceof StreamInterface ? $source->read($length) : fread($source, $length);
        if (! is_string($chunk)) {
            throw new RuntimeException('Falha ao ler o stream de mídia.');
        }

        return $chunk;
    }
}
