<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;

/**
 * Métricas de import sem labels de alta cardinalidade (sem chave/XML/nome inseguro).
 */
final class ImportMetrics
{
    public function recordZip(
        int $tenantId,
        int $entries,
        int $ok,
        int $rejected,
        int $compressedBytes,
        int $uncompressedBytes,
        float $elapsedMs,
    ): void {
        $ratio = $compressedBytes > 0 ? round($uncompressedBytes / $compressedBytes, 2) : 0.0;
        Log::info('import.metrics.zip', [
            'tenant_id' => $tenantId,
            'entries' => $entries,
            'ok' => $ok,
            'rejected' => $rejected,
            'compressed_bytes' => $compressedBytes,
            'uncompressed_bytes' => $uncompressedBytes,
            'ratio' => $ratio,
            'elapsed_ms' => (int) $elapsedMs,
        ]);
    }

    public function recordItem(int $tenantId, string $resultCode, int $byteSize, float $elapsedMs): void
    {
        // result_code é enum fechado (imported/duplicate/INVALID/…)
        Log::info('import.metrics.item', [
            'tenant_id' => $tenantId,
            'result_code' => mb_substr($resultCode, 0, 40),
            'byte_size' => $byteSize,
            'elapsed_ms' => (int) $elapsedMs,
        ]);
    }

    public function recordBatch(int $tenantId, string $status, int $items, int $imported, int $failed, float $elapsedMs): void
    {
        Log::info('import.metrics.batch', [
            'tenant_id' => $tenantId,
            'status' => mb_substr($status, 0, 40),
            'items' => $items,
            'imported' => $imported,
            'failed' => $failed,
            'elapsed_ms' => (int) $elapsedMs,
        ]);
    }

    public function recordBackpressure(int $tenantId, string $reason): void
    {
        Log::warning('import.metrics.backpressure', [
            'tenant_id' => $tenantId,
            'reason' => mb_substr($reason, 0, 80),
        ]);
    }
}
