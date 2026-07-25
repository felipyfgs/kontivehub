<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;

/**
 * Métricas de import sem labels de alta cardinalidade (sem chave/XML/nome inseguro).
 */
final class ImportMetrics
{
    public function recordZip(
        int $officeId,
        int $entries,
        int $ok,
        int $rejected,
        int $compressedBytes,
        int $uncompressedBytes,
        float $elapsedMs,
    ): void {
        $ratio = $compressedBytes > 0 ? round($uncompressedBytes / $compressedBytes, 2) : 0.0;
        Log::info('import.metrics.zip', [
            'office_id' => $officeId,
            'entries' => $entries,
            'ok' => $ok,
            'rejected' => $rejected,
            'compressed_bytes' => $compressedBytes,
            'uncompressed_bytes' => $uncompressedBytes,
            'ratio' => $ratio,
            'elapsed_ms' => (int) $elapsedMs,
        ]);
    }

    public function recordItem(int $officeId, string $resultCode, int $byteSize, float $elapsedMs): void
    {
        // result_code é enum fechado (imported/duplicate/INVALID/…)
        Log::info('import.metrics.item', [
            'office_id' => $officeId,
            'result_code' => mb_substr($resultCode, 0, 40),
            'byte_size' => $byteSize,
            'elapsed_ms' => (int) $elapsedMs,
        ]);
    }

    public function recordBatch(int $officeId, string $status, int $items, int $imported, int $failed, float $elapsedMs): void
    {
        Log::info('import.metrics.batch', [
            'office_id' => $officeId,
            'status' => mb_substr($status, 0, 40),
            'items' => $items,
            'imported' => $imported,
            'failed' => $failed,
            'elapsed_ms' => (int) $elapsedMs,
        ]);
    }

    public function recordBackpressure(int $officeId, string $reason): void
    {
        Log::warning('import.metrics.backpressure', [
            'office_id' => $officeId,
            'reason' => mb_substr($reason, 0, 80),
        ]);
    }
}
