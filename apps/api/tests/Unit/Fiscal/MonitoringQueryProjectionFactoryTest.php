<?php

namespace Tests\Unit\Fiscal;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSourceProvenance;
use App\Enums\MonitoringQueryState;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Services\FiscalMonitoring\Projection\MonitoringQueryProjectionFactory;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class MonitoringQueryProjectionFactoryTest extends TestCase
{
    public function test_maps_every_public_query_state(): void
    {
        $factory = app(MonitoringQueryProjectionFactory::class);
        $snapshot = $this->snapshot();
        $cases = [
            MonitoringQueryState::Idle->value => [null, null],
            MonitoringQueryState::Queued->value => [$this->queryRun(FiscalRunStatus::Queued), null],
            MonitoringQueryState::Processing->value => [$this->queryRun(FiscalRunStatus::Running), null],
            MonitoringQueryState::Ready->value => [
                $this->queryRun(FiscalRunStatus::Completed, FiscalRunResult::Success),
                $snapshot,
            ],
            MonitoringQueryState::NoData->value => [
                $this->queryRun(FiscalRunStatus::Completed, FiscalRunResult::Success),
                null,
            ],
            MonitoringQueryState::Failed->value => [$this->queryRun(FiscalRunStatus::Failed), null],
            MonitoringQueryState::Blocked->value => [$this->queryRun(FiscalRunStatus::Blocked), null],
            MonitoringQueryState::Unsupported->value => [
                $this->queryRun(
                    FiscalRunStatus::Skipped,
                    FiscalRunResult::Skipped,
                    FiscalCoverage::Unsupported,
                ),
                null,
            ],
        ];

        $this->assertSame(
            array_column(MonitoringQueryState::cases(), 'value'),
            array_keys($cases),
        );
        foreach ($cases as $expected => [$run, $lastSnapshot]) {
            $this->assertSame(
                $expected,
                $factory->fromModels($run, $lastSnapshot)->state->value,
            );
        }
    }

    public function test_failed_refresh_preserves_the_last_snapshot_with_freshness(): void
    {
        CarbonImmutable::setTestNow('2026-07-19T18:00:00-03:00');
        config()->set('fiscal_monitoring.projection.snapshot_freshness_ttl_seconds', 3600);
        $run = $this->queryRun(FiscalRunStatus::Failed, FiscalRunResult::Failed);
        $run->error_code = 'UPSTREAM_TIMEOUT';
        $snapshot = $this->snapshot(CarbonImmutable::now()->subHours(2));

        try {
            $public = app(MonitoringQueryProjectionFactory::class)
                ->fromModels($run, $snapshot)
                ->toArray();
        } finally {
            CarbonImmutable::setTestNow();
        }

        $this->assertSame('FAILED', $public['state']);
        $this->assertSame('UPSTREAM_TIMEOUT', $public['reason_code']);
        $this->assertTrue($public['has_preserved_snapshot']);
        $this->assertSame(77, $public['last_snapshot']['snapshot_id']);
        $this->assertSame('STALE', $public['last_snapshot']['freshness']['state']);
        $this->assertSame(7200, $public['last_snapshot']['freshness']['age_seconds']);
    }

    private function queryRun(
        FiscalRunStatus $status,
        ?FiscalRunResult $result = null,
        FiscalCoverage $coverage = FiscalCoverage::Unknown,
    ): FiscalMonitoringRun {
        $run = new FiscalMonitoringRun;
        $run->forceFill([
            'status' => $status,
            'result' => $result,
            'coverage' => $coverage,
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'created_at' => CarbonImmutable::parse('2026-07-19T17:00:00-03:00'),
        ]);
        $run->id = 42;

        return $run;
    }

    private function snapshot(?CarbonImmutable $observedAt = null): FiscalSnapshot
    {
        $snapshot = new FiscalSnapshot;
        $snapshot->forceFill([
            'coverage' => FiscalCoverage::Full,
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'observed_at' => $observedAt ?? CarbonImmutable::now(),
        ]);
        $snapshot->id = 77;

        return $snapshot;
    }
}
