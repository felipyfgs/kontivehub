<?php

namespace App\Services\FiscalMonitoring\Projection;

use App\DTO\Fiscal\Monitoring\MonitoringFreshnessDto;
use App\DTO\Fiscal\Monitoring\MonitoringQueryProjectionDto;
use App\DTO\Fiscal\Monitoring\MonitoringSnapshotReferenceDto;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSourceProvenance;
use App\Enums\MonitoringFreshnessState;
use App\Enums\MonitoringQueryState;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Traduz estados internos sem expor mensagens ou coordenadas do provider. */
final class MonitoringQueryProjectionFactory
{
    public function fromModels(
        ?FiscalMonitoringRun $run,
        ?FiscalSnapshot $lastSnapshot,
    ): MonitoringQueryProjectionDto {
        $state = $this->stateFor($run, $lastSnapshot);
        $snapshotFreshness = $this->freshness($lastSnapshot?->observed_at);
        $snapshotReference = $lastSnapshot !== null
            ? new MonitoringSnapshotReferenceDto(
                snapshotId: (int) $lastSnapshot->id,
                observedAt: $lastSnapshot->observed_at?->toIso8601String(),
                sourceProvenance: $this->enumValue(
                    $lastSnapshot->source_provenance,
                    FiscalSourceProvenance::Unverified->value,
                ),
                coverage: $this->enumValue(
                    $lastSnapshot->coverage,
                    FiscalCoverage::Unknown->value,
                ),
                freshness: $snapshotFreshness,
            )
            : null;
        $observedAt = $run?->finished_at
            ?? $run?->requeued_at
            ?? $run?->started_at
            ?? $run?->created_at
            ?? $lastSnapshot?->observed_at;
        $source = $run?->source_provenance ?? $lastSnapshot?->source_provenance;
        $coverage = $run?->coverage ?? $lastSnapshot?->coverage;
        $preservesSnapshot = $lastSnapshot !== null
            && in_array($state, [
                MonitoringQueryState::Queued,
                MonitoringQueryState::Processing,
                MonitoringQueryState::Failed,
                MonitoringQueryState::Blocked,
            ], true);

        return new MonitoringQueryProjectionDto(
            state: $state,
            observedAt: $observedAt?->toIso8601String(),
            sourceProvenance: $this->enumValue($source, FiscalSourceProvenance::Unverified->value),
            coverage: $this->enumValue($coverage, FiscalCoverage::Unknown->value),
            reasonCode: $this->sanitizedReasonCode($run, $state),
            runId: $run !== null ? (int) $run->id : null,
            freshness: $snapshotFreshness,
            lastSnapshot: $snapshotReference,
            hasPreservedSnapshot: $preservesSnapshot,
        );
    }

    private function stateFor(
        ?FiscalMonitoringRun $run,
        ?FiscalSnapshot $lastSnapshot,
    ): MonitoringQueryState {
        if ($run === null) {
            return $lastSnapshot === null
                ? MonitoringQueryState::Idle
                : MonitoringQueryState::Ready;
        }

        if ($run->coverage === FiscalCoverage::Unsupported) {
            return MonitoringQueryState::Unsupported;
        }

        return match ($run->status) {
            FiscalRunStatus::Queued => MonitoringQueryState::Queued,
            FiscalRunStatus::Running, FiscalRunStatus::Requeued => MonitoringQueryState::Processing,
            FiscalRunStatus::Failed => MonitoringQueryState::Failed,
            FiscalRunStatus::Blocked => MonitoringQueryState::Blocked,
            FiscalRunStatus::Skipped => $this->isUnsupported($run)
                ? MonitoringQueryState::Unsupported
                : MonitoringQueryState::NoData,
            FiscalRunStatus::Completed => match ($run->result) {
                FiscalRunResult::Failed => MonitoringQueryState::Failed,
                FiscalRunResult::Blocked => MonitoringQueryState::Blocked,
                FiscalRunResult::Requeued => MonitoringQueryState::Processing,
                FiscalRunResult::Skipped => $this->isUnsupported($run)
                    ? MonitoringQueryState::Unsupported
                    : MonitoringQueryState::NoData,
                default => $lastSnapshot !== null
                    ? MonitoringQueryState::Ready
                    : MonitoringQueryState::NoData,
            },
            default => MonitoringQueryState::Idle,
        };
    }

    private function isUnsupported(FiscalMonitoringRun $run): bool
    {
        return $run->coverage === FiscalCoverage::Unsupported
            || str_contains((string) $run->skip_reason, 'UNSUPPORTED')
            || str_contains((string) $run->error_code, 'UNSUPPORTED');
    }

    private function freshness(?CarbonInterface $observedAt): MonitoringFreshnessDto
    {
        $ttl = max(1, (int) config(
            'fiscal_monitoring.projection.snapshot_freshness_ttl_seconds',
            86_400,
        ));
        if ($observedAt === null) {
            return new MonitoringFreshnessDto(MonitoringFreshnessState::Unknown, null, $ttl);
        }

        $age = max(0, CarbonImmutable::now()->getTimestamp() - $observedAt->getTimestamp());

        return new MonitoringFreshnessDto(
            $age <= $ttl ? MonitoringFreshnessState::Fresh : MonitoringFreshnessState::Stale,
            $age,
            $ttl,
        );
    }

    private function sanitizedReasonCode(
        ?FiscalMonitoringRun $run,
        MonitoringQueryState $state,
    ): ?string {
        if (! in_array($state, [
            MonitoringQueryState::Failed,
            MonitoringQueryState::Blocked,
            MonitoringQueryState::NoData,
            MonitoringQueryState::Unsupported,
        ], true)) {
            return null;
        }

        $candidate = trim((string) ($run?->error_code ?: $run?->skip_reason));
        if (preg_match('/^[A-Z][A-Z0-9_.:-]{0,79}$/', $candidate) === 1) {
            return $candidate;
        }

        return match ($state) {
            MonitoringQueryState::Failed => 'QUERY_FAILED',
            MonitoringQueryState::Blocked => 'QUERY_BLOCKED',
            MonitoringQueryState::Unsupported => 'SOURCE_UNSUPPORTED',
            default => 'NO_DATA',
        };
    }

    private function enumValue(mixed $value, string $fallback): string
    {
        return $value instanceof \BackedEnum
            ? (string) $value->value
            : (is_string($value) && $value !== '' ? $value : $fallback);
    }
}
