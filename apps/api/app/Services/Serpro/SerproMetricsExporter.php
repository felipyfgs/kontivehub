<?php

namespace App\Services\Serpro;

use App\Models\SerproAsyncJobRun;
use App\Models\SerproUsageIncident;
use App\Models\SerproUsageReconciliation;
use App\Services\Operations\OperationsMetrics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Exporta métricas SERPRO sem PII (cardinalidade limitada).
 * Labels: channel, http_class, breaker_state, queue, result, class — nunca CNPJ/CPF/nome.
 */
final class SerproMetricsExporter
{
    public function __construct(
        private readonly OperationsMetrics $metrics,
        private readonly SerproCircuitBreaker $breaker,
        private readonly SerproKillSwitchService $killSwitch,
    ) {}

    /**
     * @param  array<string, scalar|null>  $labels
     */
    public function recordHttp(int $status, int $latencyMs, string $channel = 'serpro_gateway'): void
    {
        $httpClass = match (true) {
            $status === 401 => '401',
            $status === 403 => '403',
            $status === 429 => '429',
            $status >= 500 => '5xx',
            $status >= 200 && $status < 300 => '2xx',
            $status >= 400 && $status < 500 => '4xx',
            default => 'other',
        };

        $this->metrics->observeLatency('serpro.gateway.latency_ms', $latencyMs, [
            'channel' => $channel,
            'http_class' => $httpClass,
        ]);
        $this->metrics->increment('serpro.gateway.result', 1, [
            'channel' => $channel,
            'http_class' => $httpClass,
        ]);

        if (in_array($httpClass, ['401', '403', '429', '5xx'], true)) {
            $this->metrics->increment('serpro.gateway.error', 1, [
                'channel' => $channel,
                'http_class' => $httpClass,
            ]);
        }
    }

    public function recordOauth(int $status, int $latencyMs): void
    {
        $this->recordHttp($status, $latencyMs, 'serpro_oauth');
    }

    public function recordRetry(string $reasonClass = 'transient'): void
    {
        $this->metrics->increment('serpro.job.retry', 1, [
            'channel' => 'serpro',
            'result' => mb_substr($reasonClass, 0, 32),
        ]);
    }

    public function recordExpiryAlert(string $kind): void
    {
        $this->metrics->increment('serpro.lifecycle.expiry', 1, [
            'channel' => 'serpro',
            'kind' => mb_substr($kind, 0, 32),
        ]);
    }

    public function recordBudget(string $outcome): void
    {
        $this->metrics->increment('serpro.budget.gate', 1, [
            'channel' => 'serpro',
            'result' => mb_substr($outcome, 0, 32),
        ]);
    }

    public function recordUnknownBillability(): void
    {
        $this->metrics->increment('serpro.billing.unknown', 1, [
            'channel' => 'serpro',
            'result' => 'unknown',
        ]);
    }

    public function recordReconciliation(string $status): void
    {
        $this->metrics->increment('serpro.reconciliation', 1, [
            'channel' => 'serpro',
            'result' => mb_substr($status, 0, 32),
        ]);
    }

    /**
     * Snapshot sanitizado para ops/API (sem PII, sem segredos).
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $breaker = $this->breaker->globalStatus();
        $openReconcile = 0;
        $openIncidents = 0;
        $runningJobs = 0;
        $rateLimitedJobs = 0;

        try {
            if (Schema::hasTable('serpro_usage_reconciliations')) {
                $openReconcile = SerproUsageReconciliation::query()
                    ->whereIn('status', ['OPEN', 'DIVERGENT'])
                    ->count();
            }
        } catch (\Throwable) {
            $openReconcile = -1;
        }

        try {
            if (Schema::hasTable('serpro_usage_incidents')) {
                $openIncidents = SerproUsageIncident::query()
                    ->whereNull('resolved_at')
                    ->count();
            }
        } catch (\Throwable) {
            $openIncidents = -1;
        }

        try {
            if (Schema::hasTable('serpro_async_job_runs')) {
                $runningJobs = SerproAsyncJobRun::query()
                    ->where('status', SerproAsyncJobRun::STATUS_RUNNING)
                    ->count();
                $rateLimitedJobs = SerproAsyncJobRun::query()
                    ->where('status', SerproAsyncJobRun::STATUS_RATE_LIMITED)
                    ->count();
            }
        } catch (\Throwable) {
            $runningJobs = -1;
            $rateLimitedJobs = -1;
        }

        $queues = [
            'default',
            (string) config('serpro.queues.fiscal', 'fiscal'),
        ];

        $payload = [
            'kill_switch' => $this->killSwitch->status(),
            'breaker' => [
                'state' => $breaker['state'] ?? 'unknown',
                'failures' => $breaker['failures'] ?? 0,
            ],
            'queues' => $queues,
            'async_jobs_running' => $runningJobs,
            'async_jobs_rate_limited' => $rateLimitedJobs,
            'reconcile_open' => $openReconcile,
            'incidents_open' => $openIncidents,
            'ops' => $this->metrics->opsSnapshot(
                queueDepth: $runningJobs >= 0 ? $runningJobs : null,
                breakerState: (string) ($breaker['state'] ?? 'unknown'),
                reconcileOpen: $openReconcile >= 0 ? $openReconcile : null,
            ),
            // sem PII
        ];

        Log::info('serpro.metrics.snapshot', $payload);

        return $payload;
    }

    public function incrementQueueDepthGauge(string $queue, int $depth): void
    {
        Cache::put('serpro.metrics.queue_depth.'.md5($queue), max(0, $depth), 300);
        $this->metrics->increment('serpro.queue.depth_sample', 1, [
            'channel' => 'serpro',
            'queue' => mb_substr($queue, 0, 40),
        ]);
    }
}
