<?php

namespace App\Services\Outbound;

use App\Domain\Outbound\Competence;
use App\Domain\Outbound\DeadlinePlan;
use App\Domain\Outbound\OperationalSla;
use App\Enums\OutboundDeadlineSource;
use App\Enums\OutboundDeadlineStatus;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\OutboundUrgencyBand;
use App\Enums\SvrsNfceRecoveryStatus;
use App\Models\OutboundCapacitySnapshot;
use App\Models\OutboundRetrievalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Planner periódico: recalcula prazos/faixas/agenda sem materializar certificado.
 */
final class OutboundDeadlinePlannerService
{
    public function __construct(
        private readonly OutboundDeadlineCalculator $calculator,
        private readonly OutboundXmlCaptureCapacityPlanner $capacity,
        private readonly OutboundDeadlineFairQueue $fairQueue,
    ) {}

    /**
     * @return array{planned: int, snapshots: int}
     */
    public function plan(?int $tenantId = null, ?CarbonImmutable $now = null): array
    {
        if (! config('outbound_deadline.enabled') && ! config('outbound_deadline.planner_enabled')) {
            return ['planned' => 0, 'snapshots' => 0];
        }

        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $limit = (int) config('outbound_deadline.planner_batch_size', 500);

        $q = OutboundRetrievalRequest::query()
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereNotIn('recovery_status', [
                SvrsNfceRecoveryStatus::Captured->value,
                SvrsNfceRecoveryStatus::ResolvedByOtherSource->value,
                SvrsNfceRecoveryStatus::Blocked->value,
            ])
            ->orderBy('id')
            ->limit($limit);

        if ($tenantId !== null) {
            $q->where('tenant_id', $tenantId);
        }

        $rows = $q->get();
        $planned = 0;
        // Chave composta tenant_id|competence: global planner não pode misturar escritórios.
        $byTenantCompetence = [];

        foreach ($rows as $row) {
            $this->refreshDeadlineFields($row, $now);
            $planned++;
            $rowTenantId = (int) $row->tenant_id;
            $comp = (string) ($row->competence ?: 'unknown');
            $bucketKey = $rowTenantId.'|'.$comp;
            $byTenantCompetence[$bucketKey]['tenant_id'] = $rowTenantId;
            $byTenantCompetence[$bucketKey]['competence'] = $comp;
            $byTenantCompetence[$bucketKey]['first'] = ($byTenantCompetence[$bucketKey]['first'] ?? 0)
                + ((int) $row->svrs_transaction_count < 1 ? 1 : 0);
            $byTenantCompetence[$bucketKey]['second'] = ($byTenantCompetence[$bucketKey]['second'] ?? 0)
                + ((int) $row->svrs_transaction_count === 1 ? 1 : 0);
            $byTenantCompetence[$bucketKey]['target_at'] = $row->target_at;
            $byTenantCompetence[$bucketKey]['due_at'] = $row->due_at;
            $byTenantCompetence[$bucketKey]['bands'][$row->urgency_band?->value ?? 'PLANNED'] =
                ($byTenantCompetence[$bucketKey]['bands'][$row->urgency_band?->value ?? 'PLANNED'] ?? 0) + 1;
        }

        $snapshots = 0;
        foreach ($byTenantCompetence as $stats) {
            $compStr = (string) ($stats['competence'] ?? 'unknown');
            $statsTenantId = (int) ($stats['tenant_id'] ?? 0);
            if ($compStr === 'unknown' || $statsTenantId < 1) {
                continue;
            }
            try {
                $competence = Competence::fromString($compStr);
            } catch (\Throwable) {
                continue;
            }
            $proj = $this->capacity->project(
                $competence,
                (int) ($stats['first'] ?? 0),
                (int) ($stats['second'] ?? 0),
                $now,
                isset($stats['target_at']) ? CarbonImmutable::parse($stats['target_at']) : null,
                $statsTenantId,
            );

            OutboundCapacitySnapshot::query()->create([
                'tenant_id' => $statsTenantId,
                'competence' => $compStr,
                'scope' => 'TENANT',
                'demand_exchanges' => $proj['demand_exchanges'],
                'safe_capacity_exchanges' => $proj['safe_capacity_exchanges'],
                'nominal_capacity_exchanges' => $proj['nominal_capacity_exchanges'],
                'slack_exchanges' => $proj['slack_exchanges'],
                'slack_ratio' => $proj['slack_ratio'],
                'items_total' => array_sum($stats['bands'] ?? []),
                'items_planned' => $stats['bands'][OutboundUrgencyBand::Planned->value] ?? 0,
                'items_attention' => $stats['bands'][OutboundUrgencyBand::Attention->value] ?? 0,
                'items_contingency' => $stats['bands'][OutboundUrgencyBand::Contingency->value] ?? 0,
                'items_overdue' => $stats['bands'][OutboundUrgencyBand::Overdue->value] ?? 0,
                'items_captured' => $stats['bands'][OutboundUrgencyBand::Captured->value] ?? 0,
                'items_capacity_at_risk' => $proj['items_capacity_at_risk'],
                'estimated_completion_at' => $proj['estimated_completion_at'],
                'target_at' => $stats['target_at'] ?? null,
                'due_at' => $stats['due_at'] ?? null,
                'at_risk' => $proj['at_risk'],
                'metrics' => [
                    'safe_daily' => $proj['safe_daily_exchanges'] ?? null,
                    'days_window' => $proj['days_window'] ?? null,
                ],
                'calculated_at' => $now,
            ]);
            $snapshots++;

            if ($proj['at_risk']) {
                // Marca itens menos prioritários (segundas tentativas / due mais longe) — sem rajada
                // Sempre escopado por tenant_id da competência (nunca cross-tenant).
                OutboundRetrievalRequest::query()
                    ->where('tenant_id', $statsTenantId)
                    ->where('competence', $compStr)
                    ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
                    ->whereNotIn('recovery_status', [
                        SvrsNfceRecoveryStatus::Captured->value,
                        SvrsNfceRecoveryStatus::ResolvedByOtherSource->value,
                    ])
                    ->where('svrs_transaction_count', '>=', 1)
                    ->limit($proj['items_capacity_at_risk'])
                    ->update(['capacity_at_risk' => true]);
            }
        }

        // Agenda next_attempt_at para elegíveis fora de acomodação (sem dispatch se shadow)
        $this->scheduleAttempts($rows, $now);

        Log::info('outbound.deadline.planner.done', [
            'planned' => $planned,
            'snapshots' => $snapshots,
            'shadow' => (bool) config('outbound_deadline.shadow_mode', true),
            // sem access_key / CNPJ / PFX
        ]);

        try {
            app(OutboundMetrics::class)->increment('outbound.deadline.planner.runs', 1, [
                'channel' => 'deadline',
                'outcome' => config('outbound_deadline.shadow_mode', true) ? 'shadow' : 'live',
            ]);
            if ($planned > 0) {
                app(OutboundMetrics::class)->increment('outbound.deadline.planned_items', $planned, [
                    'channel' => 'deadline',
                ]);
            }
        } catch (\Throwable) {
            // métricas nunca bloqueiam o planner
        }

        return ['planned' => $planned, 'snapshots' => $snapshots];
    }

    public function refreshDeadlineFields(OutboundRetrievalRequest $row, CarbonImmutable $now): void
    {
        $plan = null;
        if ($row->access_key) {
            $plan = $this->calculator->planFromAccessKey((string) $row->access_key, null, $now);
        }
        if ($plan === null && $row->competence) {
            try {
                $comp = Competence::fromString((string) $row->competence);
                $sla = OperationalSla::fromConfig();
                $d = $sla->deadlinesFor($comp);
                $plan = new DeadlinePlan(
                    competence: $comp,
                    dueAt: $d['due_at'],
                    targetAt: $d['target_at'],
                    source: OutboundDeadlineSource::AccessKeyYm,
                    band: $this->calculator->band($d['due_at'], $d['target_at'], $now, false, (bool) $row->capacity_at_risk),
                    provisional: true,
                );
            } catch (\Throwable) {
                return;
            }
        }
        if ($plan === null) {
            return;
        }

        $captured = $row->recovery_status === SvrsNfceRecoveryStatus::Captured
            || $row->recovery_status === SvrsNfceRecoveryStatus::ResolvedByOtherSource;

        $band = $captured
            ? OutboundUrgencyBand::Captured
            : $this->calculator->band($plan->dueAt, $plan->targetAt, $now, false, (bool) $row->capacity_at_risk);

        $root = null;
        if ($row->access_key && strlen((string) $row->access_key) >= 14) {
            $root = substr((string) $row->access_key, 6, 8);
        }

        $hours = $this->calculator->accommodationHours($band, $plan->targetAt, $now);
        $accommodationUntil = $hours > 0
            ? ($row->created_at
                ? CarbonImmutable::parse($row->created_at)->addHours($hours)
                : $now->addHours($hours))
            : null;

        $deadlineStatus = match ($band) {
            OutboundUrgencyBand::Captured => OutboundDeadlineStatus::Met,
            OutboundUrgencyBand::Overdue => OutboundDeadlineStatus::Missed,
            OutboundUrgencyBand::Contingency, OutboundUrgencyBand::Attention => OutboundDeadlineStatus::AtRisk,
            default => OutboundDeadlineStatus::OnTrack,
        };

        $row->forceFill([
            'competence' => $plan->competence->value(),
            'due_at' => $plan->dueAt,
            'target_at' => $plan->targetAt,
            'deadline_source' => $plan->source,
            'urgency_band' => $band,
            'deadline_status' => $deadlineStatus,
            'root_cnpj' => $root,
            'accommodation_until' => $accommodationUntil,
            'planned_at' => $now,
        ])->save();
    }

    /**
     * @param  Collection<int, OutboundRetrievalRequest>  $rows
     */
    private function scheduleAttempts($rows, CarbonImmutable $now): void
    {
        $candidates = $rows->filter(function (OutboundRetrievalRequest $r) {
            if ($r->urgency_band === OutboundUrgencyBand::Captured) {
                return false;
            }
            if ($r->accommodation_until !== null && $r->accommodation_until->isFuture()) {
                return false;
            }
            $maxTx = (int) config('outbound_deadline.max_svrs_transactions_per_key', 2);
            if ((int) $r->svrs_transaction_count >= $maxTx) {
                return false;
            }
            if ((int) $r->svrs_transaction_count >= 1) {
                $minH = (int) config('outbound_deadline.min_hours_between_svrs_attempts', 24);
                // exige que última tentativa tenha sido há ≥ minH (aproximado via updated_at)
                if ($r->updated_at && CarbonImmutable::parse($r->updated_at)->addHours($minH)->isFuture()) {
                    return false;
                }
            }

            return true;
        });

        $ordered = $this->fairQueue->order($candidates);
        $selected = $this->fairQueue->fairSelect($ordered, count($ordered));

        $window = 3600; // spread dentro de 1h para não compactar
        foreach ($selected as $item) {
            /** @var OutboundRetrievalRequest $item */
            $spread = $this->fairQueue->spreadSeconds(
                (string) $item->tenant_id.'|'.(string) $item->access_key.'|'.(string) $item->svrs_transaction_count,
                $window,
            );
            $next = $now->addSeconds($spread);
            if ($item->accommodation_until && $item->accommodation_until->greaterThan($next)) {
                $next = CarbonImmutable::parse($item->accommodation_until);
            }
            $item->forceFill([
                'next_attempt_at' => $next,
                'slot_key' => $item->tenant_id.'|'.$item->access_key.'|'.((int) $item->svrs_transaction_count + 1),
            ])->save();
        }
    }
}
