<?php

namespace App\Services\Serpro;

use App\Models\SerproAsyncJobRun;
use App\Models\SerproDocumentSnapshot;
use App\Models\SerproUsageBudget;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Alertas operacionais com links de runbook — sem PII nos labels/payloads de log.
 */
final class SerproOpsAlertService
{
    public function __construct(
        private readonly SerproLifecycleMonitor $lifecycle,
        private readonly SerproCircuitBreaker $breaker,
        private readonly SerproMetricsExporter $metrics,
        private readonly SerproDocumentRegistry $documents,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{
     *   alerts: list<array<string, mixed>>,
     *   lifecycle: array<string, mixed>,
     *   metrics: array<string, mixed>
     * }
     */
    public function scan(): array
    {
        $alerts = [];
        $lifecycle = $this->lifecycle->scan();

        foreach ($lifecycle['alerts'] as $a) {
            $kind = (string) ($a['kind'] ?? 'UNKNOWN');
            $runbook = $this->runbookForKind($kind);
            $alerts[] = [
                'kind' => $kind,
                'severity' => $a['severity'] ?? null,
                'office_id' => $a['office_id'] ?? null,
                'subject_id' => $a['subject_id'] ?? null,
                'days_left' => $a['days_left'] ?? null,
                'runbook' => $runbook,
                'scope' => ($a['office_id'] ?? null) !== null ? 'OFFICE' : 'GLOBAL',
            ];
            $this->metrics->recordExpiryAlert($kind);
        }

        $breaker = $this->breaker->globalStatus();
        if (($breaker['state'] ?? '') === 'open') {
            $alerts[] = [
                'kind' => 'BREAKER_OPEN',
                'severity' => 'CRITICAL',
                'office_id' => null,
                'runbook' => $this->runbook('breaker_open'),
                'scope' => 'GLOBAL',
                'message' => 'Circuit breaker global aberto.',
            ];
        }

        $stuckSeconds = max(60, (int) config('serpro.observability.stuck_queue_seconds', 900));
        if (Schema::hasTable('serpro_async_job_runs')) {
            $stuck = SerproAsyncJobRun::query()
                ->where('status', SerproAsyncJobRun::STATUS_RUNNING)
                ->where('started_at', '<', CarbonImmutable::now()->subSeconds($stuckSeconds))
                ->count();
            if ($stuck > 0) {
                $alerts[] = [
                    'kind' => 'QUEUE_STUCK',
                    'severity' => 'HIGH',
                    'count' => $stuck,
                    'stuck_after_seconds' => $stuckSeconds,
                    'runbook' => $this->runbook('queue_stuck'),
                    'scope' => 'GLOBAL',
                ];
            }
        }

        // Budget: limit esgotado (limit - reserved - consumed <= 0), sem PII
        try {
            if (Schema::hasTable('serpro_usage_budgets')) {
                $exhausted = SerproUsageBudget::query()
                    ->where('is_active', true)
                    ->where('limit_micros', '>', 0)
                    ->whereRaw('(limit_micros - reserved_micros - consumed_micros) <= 0')
                    ->count();
                if ($exhausted > 0) {
                    $alerts[] = [
                        'kind' => 'BUDGET_EXHAUSTED',
                        'severity' => 'HIGH',
                        'count' => $exhausted,
                        'runbook' => $this->runbook('budget_exceeded'),
                        'scope' => 'GLOBAL',
                    ];
                }
            }
        } catch (\Throwable) {
            // schema pode divergir
        }

        // Document drift: manifesto vs snapshots se hash divergir
        try {
            $manifest = $this->documents->loadManifest();
            $sources = $manifest['sources'] ?? [];
            if (is_array($sources) && Schema::hasTable('serpro_document_snapshots')) {
                foreach ($sources as $src) {
                    if (! is_array($src) || empty($src['source_key']) || empty($src['content_sha256'])) {
                        continue;
                    }
                    $latest = SerproDocumentSnapshot::query()
                        ->where('source_key', $src['source_key'])
                        ->orderByDesc('id')
                        ->first();
                    if ($latest !== null && $latest->content_sha256 !== $src['content_sha256']) {
                        $alerts[] = [
                            'kind' => 'DOCUMENT_DRIFT',
                            'severity' => 'MEDIUM',
                            'source_key' => $src['source_key'],
                            'runbook' => $this->runbook('document_drift'),
                            'scope' => 'GLOBAL',
                        ];
                    }
                }
            }
        } catch (\Throwable) {
            // manifesto indisponível já coberto no readiness
        }

        $metrics = $this->metrics->snapshot();

        foreach ($alerts as $alert) {
            Log::warning('serpro.ops.alert', [
                'kind' => $alert['kind'],
                'severity' => $alert['severity'] ?? null,
                'scope' => $alert['scope'] ?? null,
                'runbook' => $alert['runbook'] ?? null,
                // sem PII
            ]);
        }

        if ($alerts !== []) {
            $this->audit->record('serpro.ops.scan', 'SUCCESS', null, [
                'alerts_count' => count($alerts),
                'kinds' => array_values(array_unique(array_column($alerts, 'kind'))),
            ], null, null);
        }

        return [
            'alerts' => $alerts,
            'lifecycle' => [
                'scanned' => $lifecycle['scanned'] ?? [],
                'lock_acquired' => $lifecycle['lock_acquired'] ?? false,
                'alerts_count' => count($lifecycle['alerts'] ?? []),
            ],
            'metrics' => $metrics,
            'runbooks' => config('serpro.observability.runbooks', []),
        ];
    }

    public function runbook(string $key): ?string
    {
        /** @var array<string, string> $map */
        $map = config('serpro.observability.runbooks', []);

        return $map[$key] ?? null;
    }

    private function runbookForKind(string $kind): ?string
    {
        return match ($kind) {
            'CONTRACTOR_PFX', 'AUTHOR_A1' => $this->runbook('cert_expiry'),
            'TERMO' => $this->runbook('termo_rejected'),
            'PROCURADOR_TOKEN' => $this->runbook('http_401'),
            'PROXY_POWER' => $this->runbook('cert_expiry'),
            default => $this->runbook('cert_expiry'),
        };
    }
}
