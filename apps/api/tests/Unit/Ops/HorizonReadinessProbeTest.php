<?php

namespace Tests\Unit\Ops;

use App\Services\Ops\HorizonReadinessProbe;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HorizonReadinessProbeTest extends TestCase
{
    #[Test]
    public function healthy_workloads_report_ready_with_low_cardinality_detail(): void
    {
        config([
            'horizon.readiness_thresholds.queues' => [
                'default' => $this->limits(),
                'fiscal' => $this->limits(),
            ],
        ]);
        $masters = $this->createMock(MasterSupervisorRepository::class);
        $masters->method('all')->willReturn([(object) ['name' => 'master']]);
        $workloads = $this->createMock(WorkloadRepository::class);
        $workloads->method('get')->willReturn([
            ['name' => 'default', 'length' => 1, 'wait' => 10, 'processes' => 1, 'split_queues' => null],
            ['name' => 'fiscal', 'length' => 0, 'wait' => 0, 'processes' => 1, 'split_queues' => null],
        ]);
        $metrics = $this->createMock(MetricsRepository::class);
        $metrics->method('throughputForQueue')->willReturn(5);
        $metrics->method('runtimeForQueue')->willReturn(100.0);
        $jobs = $this->createMock(JobRepository::class);
        $jobs->method('getRecent')->willReturn(new Collection([
            (object) ['queue' => 'default', 'payload' => json_encode(['attempts' => 1])],
        ]));
        $jobs->method('getFailed')->willReturn(new Collection);

        $result = (new HorizonReadinessProbe($masters, $workloads, $metrics, $jobs))->check();

        $this->assertTrue($result['ok']);
        $this->assertSame('masters=1;queues=2', $result['detail']);
    }

    #[Test]
    public function breached_queue_metrics_degrade_readiness(): void
    {
        config([
            'horizon.readiness_thresholds.queues' => [
                'fiscal' => $this->limits(),
            ],
        ]);
        $masters = $this->createMock(MasterSupervisorRepository::class);
        $masters->method('all')->willReturn([(object) ['name' => 'master']]);
        $workloads = $this->createMock(WorkloadRepository::class);
        $workloads->method('get')->willReturn([
            ['name' => 'fiscal', 'length' => 11, 'wait' => 31, 'processes' => 1, 'split_queues' => null],
        ]);
        $metrics = $this->createMock(MetricsRepository::class);
        $metrics->method('throughputForQueue')->willReturn(0);
        $metrics->method('runtimeForQueue')->willReturn(1001.0);
        $jobs = $this->createMock(JobRepository::class);
        $jobs->method('getRecent')->willReturn(new Collection([
            (object) ['queue' => 'fiscal', 'payload' => json_encode(['attempts' => 2])],
            (object) ['queue' => 'fiscal', 'payload' => json_encode(['retry_of' => 'opaque'])],
        ]));
        $jobs->method('getFailed')->willReturn(new Collection([
            (object) ['queue' => 'fiscal', 'payload' => '{}'],
            (object) ['queue' => 'fiscal', 'payload' => '{}'],
        ]));

        $result = (new HorizonReadinessProbe($masters, $workloads, $metrics, $jobs))->check();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('fiscal:pending=11>10', $result['detail']);
        $this->assertStringContainsString('fiscal:wait=31>30', $result['detail']);
        $this->assertStringContainsString('fiscal:runtime=1001>1000', $result['detail']);
        $this->assertStringContainsString('fiscal:retries=2>1', $result['detail']);
        $this->assertStringContainsString('fiscal:failures=2>1', $result['detail']);
        $this->assertStringContainsString('fiscal:throughput=0<1', $result['detail']);
    }

    /** @return array<string, int> */
    private function limits(): array
    {
        return [
            'max_pending' => 10,
            'max_wait_seconds' => 30,
            'min_throughput_when_pending' => 1,
            'max_runtime_ms' => 1000,
            'max_recent_retries' => 1,
            'max_recent_failures' => 1,
        ];
    }
}
