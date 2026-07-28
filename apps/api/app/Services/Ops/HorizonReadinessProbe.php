<?php

namespace App\Services\Ops;

use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Throwable;

final readonly class HorizonReadinessProbe
{
    public function __construct(
        private MasterSupervisorRepository $masters,
        private WorkloadRepository $workloads,
        private MetricsRepository $metrics,
        private JobRepository $jobs,
    ) {}

    /**
     * @return array{id: string, ok: bool, detail: string}
     */
    public function check(): array
    {
        try {
            $masters = $this->masters->all();
            $masterCount = is_countable($masters) ? count($masters) : 0;
            if ($masterCount < 1) {
                return ['id' => 'horizon', 'ok' => false, 'detail' => 'no_master_supervisor'];
            }

            $thresholds = (array) config('horizon.readiness_thresholds.queues', []);
            if ($thresholds === []) {
                return ['id' => 'horizon', 'ok' => false, 'detail' => 'thresholds_missing'];
            }

            $observed = $this->workloadsByQueue($this->workloads->get());
            $recentRetries = $this->recentCounts($this->jobs->getRecent(), retries: true);
            $recentFailures = $this->recentCounts($this->jobs->getFailed(), retries: false);
            $issues = [];

            foreach ($thresholds as $queue => $limits) {
                if (! is_string($queue) || ! is_array($limits)) {
                    continue;
                }

                $length = (int) ($observed[$queue]['length'] ?? 0);
                $wait = (int) ($observed[$queue]['wait'] ?? 0);
                $throughput = (int) $this->metrics->throughputForQueue($queue);
                $runtime = (float) $this->metrics->runtimeForQueue($queue);
                $retries = (int) ($recentRetries[$queue] ?? 0);
                $failures = (int) ($recentFailures[$queue] ?? 0);

                $this->appendThresholdIssue(
                    $issues,
                    $queue,
                    'pending',
                    $length,
                    (int) ($limits['max_pending'] ?? 0),
                );
                $this->appendThresholdIssue(
                    $issues,
                    $queue,
                    'wait',
                    $wait,
                    (int) ($limits['max_wait_seconds'] ?? 0),
                );
                $this->appendThresholdIssue(
                    $issues,
                    $queue,
                    'runtime',
                    (int) ceil($runtime),
                    (int) ($limits['max_runtime_ms'] ?? 0),
                );
                $this->appendThresholdIssue(
                    $issues,
                    $queue,
                    'retries',
                    $retries,
                    (int) ($limits['max_recent_retries'] ?? 0),
                );
                $this->appendThresholdIssue(
                    $issues,
                    $queue,
                    'failures',
                    $failures,
                    (int) ($limits['max_recent_failures'] ?? 0),
                );

                $minimumThroughput = (int) ($limits['min_throughput_when_pending'] ?? 0);
                if ($length > 0 && $throughput < $minimumThroughput) {
                    $issues[] = "{$queue}:throughput={$throughput}<{$minimumThroughput}";
                }
            }

            if ($issues !== []) {
                return [
                    'id' => 'horizon',
                    'ok' => false,
                    'detail' => implode(';', array_slice($issues, 0, 12)),
                ];
            }

            return [
                'id' => 'horizon',
                'ok' => true,
                'detail' => "masters={$masterCount};queues=".count($thresholds),
            ];
        } catch (Throwable) {
            return ['id' => 'horizon', 'ok' => false, 'detail' => 'check_failed'];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $workloads
     * @return array<string, array{length: int, wait: int}>
     */
    private function workloadsByQueue(array $workloads): array
    {
        $observed = [];
        foreach ($workloads as $workload) {
            $split = $workload['split_queues'] ?? null;
            if (is_array($split)) {
                foreach ($split as $queue) {
                    if (! is_array($queue) || ! is_string($queue['name'] ?? null)) {
                        continue;
                    }
                    $observed[$queue['name']] = [
                        'length' => (int) ($queue['length'] ?? 0),
                        'wait' => (int) ($queue['wait'] ?? 0),
                    ];
                }

                continue;
            }

            if (! is_string($workload['name'] ?? null)) {
                continue;
            }
            $observed[$workload['name']] = [
                'length' => (int) ($workload['length'] ?? 0),
                'wait' => (int) ($workload['wait'] ?? 0),
            ];
        }

        return $observed;
    }

    /**
     * @return array<string, int>
     */
    private function recentCounts(iterable $jobs, bool $retries): array
    {
        $counts = [];
        foreach ($jobs as $job) {
            $queue = is_object($job) ? ($job->queue ?? null) : null;
            if (! is_string($queue) || $queue === '') {
                continue;
            }
            if ($retries) {
                $payload = json_decode((string) ($job->payload ?? ''), true);
                $attempts = is_array($payload) ? (int) ($payload['attempts'] ?? 0) : 0;
                $retryOf = is_array($payload) ? ($payload['retry_of'] ?? null) : null;
                if ($attempts < 2 && ! is_string($retryOf)) {
                    continue;
                }
            }
            $counts[$queue] = ($counts[$queue] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  list<string>  $issues
     */
    private function appendThresholdIssue(
        array &$issues,
        string $queue,
        string $metric,
        int $value,
        int $maximum,
    ): void {
        if ($maximum > 0 && $value > $maximum) {
            $issues[] = "{$queue}:{$metric}={$value}>{$maximum}";
        }
    }
}
