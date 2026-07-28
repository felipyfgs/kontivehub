<?php

namespace App\Services\Outbound;

use App\Domain\Outbound\Competence;
use App\Domain\Outbound\OperationalSla;
use App\DTO\Outbound\OutboundCapacityForecastData;
use App\DTO\Outbound\OutboundCompetenceFilter;
use App\DTO\Outbound\OutboundCompetenceSummaryData;
use App\DTO\Outbound\OutboundPendingFilters;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\OutboundUrgencyBand;
use App\Models\OutboundCapacitySnapshot;
use App\Models\OutboundRetrievalRequest;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class OutboundDeadlineQuery
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private OutboundMonthlyReadinessService $readiness,
        private OutboundDeadlineSatisfactionService $satisfaction,
        private OutboundXmlCaptureCapacityPlanner $capacity,
        private OutboundMetrics $metrics,
    ) {}

    public function competenceSummary(
        OutboundCompetenceFilter $filter,
    ): OutboundCompetenceSummaryData {
        $tenantId = (int) $this->currentTenant->id();
        $competence = $filter->valueOrCurrent();
        $stats = $this->readiness->compute($tenantId, $competence);
        $ready = $this->readiness->refresh($tenantId, $competence);
        $bySource = OutboundRetrievalRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('competence', $competence)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereNotNull('capture_source')
            ->selectRaw('capture_source, count(*) as c')
            ->groupBy('capture_source')
            ->pluck('c', 'capture_source')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return new OutboundCompetenceSummaryData(
            competence: $competence,
            stats: $stats,
            byCaptureSource: $bySource,
            readiness: $ready,
        );
    }

    public function capacityForecast(
        OutboundCompetenceFilter $filter,
    ): OutboundCapacityForecastData {
        $tenantId = (int) $this->currentTenant->id();
        $competence = $filter->valueOrCurrent();
        $value = Competence::fromString($competence);
        $base = OutboundRetrievalRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('competence', $competence)
            ->whereNotIn('urgency_band', [
                OutboundUrgencyBand::Captured->value,
            ]);
        $first = (clone $base)
            ->where('svrs_transaction_count', 0)
            ->count();
        $second = (clone $base)
            ->where('svrs_transaction_count', 1)
            ->count();
        $sla = OperationalSla::fromConfig(
            $this->currentTenant->tenant()?->deadline_timezone,
        );
        $deadlines = $sla->deadlinesFor($value);
        $projection = $this->capacity->project(
            $value,
            $first,
            $second,
            CarbonImmutable::now('UTC'),
            $deadlines['target_at'],
            $tenantId,
        );
        $latestSnapshot = OutboundCapacitySnapshot::query()
            ->where('tenant_id', $tenantId)
            ->where('competence', $competence)
            ->orderByDesc('id')
            ->first();

        return new OutboundCapacityForecastData(
            competence: $competence,
            projection: [
                'demand_exchanges' => $projection['demand_exchanges'],
                'safe_capacity_exchanges' => $projection['safe_capacity_exchanges'],
                'nominal_capacity_exchanges' => $projection['nominal_capacity_exchanges'],
                'slack_exchanges' => $projection['slack_exchanges'],
                'at_risk' => $projection['at_risk'],
                'items_capacity_at_risk' => $projection['items_capacity_at_risk'],
                'safe_daily_exchanges' => $projection['safe_daily_exchanges'],
                'auto_queue_fraction' => (float) config(
                    'outbound_deadline.auto_queue_capacity_fraction',
                    0.6,
                ),
                'estimated_completion_at' => $projection['estimated_completion_at']
                    ?->toIso8601String(),
                'target_at' => $deadlines['target_at']->toIso8601String(),
                'due_at' => $deadlines['due_at']->toIso8601String(),
            ],
            latestSnapshot: $latestSnapshot,
        );
    }

    /** @return LengthAwarePaginator<OutboundRetrievalRequest> */
    public function pending(OutboundPendingFilters $filters): LengthAwarePaginator
    {
        $tenantId = (int) $this->currentTenant->id();
        $query = OutboundRetrievalRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereNotIn('urgency_band', [
                OutboundUrgencyBand::Captured->value,
            ]);
        if ($filters->competence !== null) {
            $query->where('competence', $filters->competence);
        }
        if ($filters->urgencyBand !== null) {
            $query->where('urgency_band', $filters->urgencyBand->value);
        }
        if ($filters->model !== null) {
            $query->where('model', $filters->model->value);
        }
        if ($filters->rootCnpjPrefix !== null) {
            $query->where(
                'root_cnpj',
                'like',
                $filters->rootCnpjPrefix.'%',
            );
        }
        if ($filters->clientId !== null) {
            $query->whereHas(
                'profile',
                fn ($profile) => $profile
                    ->where('client_id', $filters->clientId)
                    ->where('tenant_id', $tenantId),
            );
        }
        if ($filters->source !== null) {
            $query->where(
                'capture_source',
                'like',
                '%'.$filters->source.'%',
            );
        }

        $query->orderBy($filters->sort, $filters->direction);
        if ($filters->sort !== 'id') {
            $query->orderBy('id', $filters->direction);
        }

        return $query->paginate($filters->perPage);
    }

    /** @return list<array<string, mixed>> */
    public function contingency(OutboundCompetenceFilter $filter): array
    {
        return $this->satisfaction->contingencyBatch(
            (int) $this->currentTenant->id(),
            $filter->value(),
        );
    }

    /** @return array<string, mixed> */
    public function metrics(OutboundCompetenceFilter $filter): array
    {
        return $this->metrics->deadlineSnapshot(
            (int) $this->currentTenant->id(),
            $filter->value(),
        );
    }
}
