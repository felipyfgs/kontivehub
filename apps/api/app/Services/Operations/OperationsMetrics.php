<?php

namespace App\Services\Operations;

use App\Support\LogSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Métricas operacionais de baixa cardinalidade.
 * Labels: ambiente, resultado, 429, breaker, fila, consumo, reconciliação —
 * sem CNPJ completo, chave de acesso ou material fiscal.
 */
final class OperationsMetrics
{
    /**
     * @param  array<string, scalar|null>  $labels
     */
    public function increment(string $name, int $by = 1, array $labels = []): void
    {
        $safeLabels = LogSanitizer::metricLabels($labels);

        Log::info('metrics.counter', [
            'name' => $name,
            'by' => $by,
            'labels' => $safeLabels,
        ]);

        $key = 'metrics.counter.'.$name.'.'.md5(json_encode($safeLabels) ?: '');
        try {
            Cache::increment($key, $by);
        } catch (\Throwable) {
            $cur = (int) Cache::get($key, 0);
            Cache::put($key, $cur + $by, 86400);
        }
    }

    /**
     * @param  array<string, scalar|null>  $labels
     */
    public function observeLatency(string $name, int $latencyMs, array $labels = []): void
    {
        $safeLabels = LogSanitizer::metricLabels($labels);

        Log::info('metrics.histogram', [
            'name' => $name,
            'value_ms' => max(0, $latencyMs),
            'labels' => $safeLabels,
        ]);

        $bucket = $this->latencyBucket($latencyMs);
        $this->increment($name.'.bucket', 1, array_merge($safeLabels, [
            'outcome' => $bucket,
        ]));
    }

    /**
     * Snapshot sanitizado de filas / breaker / consumo (sem PII).
     *
     * @return array<string, mixed>
     */
    public function opsSnapshot(
        ?int $queueDepth = null,
        ?string $breakerState = null,
        ?int $usageQuantity = null,
        ?int $reconcileOpen = null,
    ): array {
        return [
            'queue_depth' => $queueDepth,
            'breaker_state' => $breakerState !== null
                ? mb_substr($breakerState, 0, 32)
                : null,
            'usage_quantity' => $usageQuantity,
            'reconcile_open' => $reconcileOpen,
        ];
    }

    private function latencyBucket(int $ms): string
    {
        return match (true) {
            $ms < 100 => 'lt_100ms',
            $ms < 500 => 'lt_500ms',
            $ms < 2000 => 'lt_2s',
            $ms < 10000 => 'lt_10s',
            default => 'gte_10s',
        };
    }
}
