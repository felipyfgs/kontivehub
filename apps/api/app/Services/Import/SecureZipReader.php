<?php

namespace App\Services\Import;

use RuntimeException;
use ZipArchive;

/**
 * Leitura segura de ZIP de importação fiscal.
 * Preflight do central directory + streaming com limites configuráveis.
 * Nunca usa path de entrada para filesystem; descarta buffers por item.
 */
final class SecureZipReader
{
    /**
     * @return list<array{name: string, bytes: string, size: int, status: string, message?: string}>
     */
    public function extractXmlEntries(string $zipBytes, string $zipName = 'upload.zip'): array
    {
        $maxEntries = (int) config('import.max_xml_entries_per_batch', 5000);
        $maxXml = (int) config('import.max_xml_bytes', 5 * 1024 * 1024);
        $maxUncompressed = (int) config('import.max_batch_uncompressed_bytes', 250 * 1024 * 1024);
        $maxRatio = (float) config('import.max_compression_ratio', 100.0);

        $compressedSize = strlen($zipBytes);
        if ($compressedSize === 0) {
            throw new RuntimeException('ZIP vazio.');
        }

        // ZIP multi-disk / spanned: signature de volume extra
        if (str_starts_with($zipBytes, "PK\x07\x08") || str_starts_with($zipBytes, "PK\x00\x00")) {
            throw new RuntimeException('ZIP multi-disco ou spanning não suportado.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'seczip');
        if ($tmp === false) {
            throw new RuntimeException('Falha ao criar temporário para ZIP.');
        }

        try {
            file_put_contents($tmp, $zipBytes);
            // descarta cópia em memória do caller o quanto antes (referência local)
            unset($zipBytes);

            $zip = new ZipArchive;
            $open = $zip->open($tmp, ZipArchive::RDONLY);
            if ($open !== true) {
                throw new RuntimeException('ZIP inválido ou corrompido.');
            }

            try {
                return $this->walk($zip, $zipName, $maxEntries, $maxXml, $maxUncompressed, $maxRatio, $compressedSize);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return list<array{name: string, bytes: string, size: int, status: string, message?: string}>
     */
    private function walk(
        ZipArchive $zip,
        string $zipName,
        int $maxEntries,
        int $maxXml,
        int $maxUncompressed,
        float $maxRatio,
        int $compressedSize,
    ): array {
        $out = [];
        $xmlCount = 0;
        $uncompressedTotal = 0;
        $seenNames = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i, ZipArchive::FL_ENC_RAW);
            if ($stat === false) {
                $out[] = [
                    'name' => $zipName.'#'.$i,
                    'bytes' => '',
                    'size' => 0,
                    'status' => 'error',
                    'message' => 'Metadado de entrada inconsistente.',
                ];

                continue;
            }

            $entryName = (string) ($stat['name'] ?? '');
            $isDir = str_ends_with($entryName, '/') || (($stat['external_attr'] ?? 0) & 0x10) === 0x10
                || (($stat['external_attr'] ?? 0) & 0x40000000) === 0x40000000;

            if ($isDir || $entryName === '' || str_ends_with($entryName, '/')) {
                continue;
            }

            // Encrypted entry
            if ((($stat['encryption_method'] ?? 0) > 0) || ((($stat['comp_method'] ?? 0) === 99))) {
                $out[] = $this->reject($zipName, $entryName, 'ZIP criptografado não suportado.');

                continue;
            }

            $safeLabel = $this->safeLabel($entryName);

            // Path safety
            if (str_contains($entryName, "\0") || str_contains($safeLabel, "\0")) {
                $out[] = $this->reject($zipName, $entryName, 'Nome de entrada com NUL.');

                continue;
            }
            if ($this->isAbsoluteOrTraversal($entryName)) {
                $out[] = $this->reject($zipName, $entryName, 'Caminho absoluto ou traversal rejeitado.');

                continue;
            }

            // Symlink / special (unix external attrs high bits)
            $mode = (($stat['external_attr'] ?? 0) >> 16) & 0xFFFF;
            if ($mode !== 0 && (($mode & 0xF000) === 0xA000 || ($mode & 0xF000) === 0x2000 || ($mode & 0xF000) === 0x6000)) {
                $out[] = $this->reject($zipName, $entryName, 'Symlink ou dispositivo não suportado.');

                continue;
            }

            $normalized = $this->normalizeName($entryName);
            if (isset($seenNames[$normalized])) {
                $out[] = $this->reject($zipName, $entryName, 'Nome de entrada normalizado duplicado.');

                continue;
            }
            $seenNames[$normalized] = true;

            $declared = (int) ($stat['size'] ?? 0);
            $compSize = (int) ($stat['comp_size'] ?? 0);

            if ($declared > $maxXml) {
                $out[] = $this->reject($zipName, $entryName, 'XML excede limite por entrada.');

                continue;
            }

            if ($compSize > 0 && $declared / max(1, $compSize) > $maxRatio) {
                $out[] = $this->reject($zipName, $entryName, 'Razão de compressão excessiva.');

                continue;
            }

            // Somente XML fiscal candidato; demais tipos com estado explícito
            $base = basename(str_replace('\\', '/', $entryName));
            if (str_starts_with($base, '.')) {
                $out[] = $this->reject($zipName, $entryName, 'Entrada oculta não suportada.', 'unsupported');

                continue;
            }
            if (! preg_match('/\.xml$/i', $base)) {
                // Nested zip?
                if (preg_match('/\.zip$/i', $base)) {
                    $out[] = $this->reject($zipName, $entryName, 'ZIP aninhado não suportado.');

                    continue;
                }
                $out[] = $this->reject($zipName, $entryName, 'Tipo de arquivo não suportado.', 'unsupported');

                continue;
            }

            $xmlCount++;
            if ($xmlCount > $maxEntries) {
                throw new RuntimeException('ZIP excede teto de entradas XML do lote.');
            }

            // Stream entry — ZipArchive::getFromIndex with length cap
            $content = $zip->getFromIndex($i, $maxXml + 1);
            if ($content === false) {
                $out[] = $this->reject($zipName, $entryName, 'Falha ao ler entrada.');

                continue;
            }

            $size = strlen($content);
            if ($size === 0) {
                $out[] = $this->reject($zipName, $entryName, 'Entrada ZIP vazia.');
                unset($content);

                continue;
            }
            if ($size > $maxXml) {
                $out[] = $this->reject($zipName, $entryName, 'XML excede limite por entrada.');
                unset($content);

                continue;
            }

            // Nested ZIP by magic
            if (str_starts_with($content, "PK\x03\x04") || str_starts_with($content, "PK\x05\x06")) {
                $out[] = $this->reject($zipName, $entryName, 'ZIP aninhado (magic) não suportado.');
                unset($content);

                continue;
            }

            $uncompressedTotal += $size;
            if ($uncompressedTotal > $maxUncompressed) {
                unset($content);
                throw new RuntimeException('ZIP excede teto de bytes descompactados do lote.');
            }

            if ($compressedSize > 0 && $uncompressedTotal / $compressedSize > $maxRatio) {
                unset($content);
                throw new RuntimeException('Razão de compressão global do ZIP excessiva.');
            }

            // Declared size vs actual (inconsistência de metadado)
            if ($declared > 0 && abs($declared - $size) > 0 && $declared !== $size) {
                // Stored/deflate can differ only if wrong — prefer actual size; flag large mismatch
                if ($declared > $maxXml) {
                    $out[] = $this->reject($zipName, $entryName, 'Metadado de tamanho inconsistente.');
                    unset($content);

                    continue;
                }
            }

            $out[] = [
                'name' => $zipName.'/'.$base,
                'bytes' => $content,
                'size' => $size,
                'status' => 'ok',
            ];
            unset($content);
        }

        if ($out === []) {
            throw new RuntimeException('ZIP sem entradas processáveis.');
        }

        return $out;
    }

    /**
     * @return array{name: string, bytes: string, size: int, status: string, message: string}
     */
    private function reject(string $zipName, string $entry, string $message, string $status = 'error'): array
    {
        return [
            'name' => $zipName.'/'.$this->safeLabel($entry),
            'bytes' => '',
            'size' => 0,
            'status' => $status,
            'message' => $message,
        ];
    }

    private function isAbsoluteOrTraversal(string $name): bool
    {
        $n = str_replace('\\', '/', $name);
        if (str_starts_with($n, '/') || preg_match('#^[A-Za-z]:/#', $n)) {
            return true;
        }
        foreach (explode('/', $n) as $seg) {
            if ($seg === '..') {
                return true;
            }
        }

        return false;
    }

    private function normalizeName(string $name): string
    {
        $n = strtolower(str_replace('\\', '/', $name));
        $n = preg_replace('#/+#', '/', $n) ?? $n;

        return trim($n, '/');
    }

    private function safeLabel(string $entry): string
    {
        $base = basename(str_replace('\\', '/', $entry));
        $base = preg_replace('/[^\w.\-]+/u', '_', $base) ?? 'entry';

        return mb_substr($base, 0, 180);
    }
}
