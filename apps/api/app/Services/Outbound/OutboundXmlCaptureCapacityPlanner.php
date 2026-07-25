<?php

namespace App\Services\Outbound;

use App\Contracts\OutboundXmlCaptureCapacityPlanner as OutboundXmlCaptureCapacityPlannerContract;
use App\Contracts\SvrsPortalEgressGovernor;
use App\Domain\Outbound\Competence;
use Carbon\CarbonImmutable;

/**
 * Projeta demanda vs capacidade segura (60% default) sem acumular burst.
 * Lê budgets do SvrsPortalEgressGovernor (fail-closed se breaker aberto).
 */
final class OutboundXmlCaptureCapacityPlanner implements OutboundXmlCaptureCapacityPlannerContract
{
    public function __construct(
        private readonly SvrsPortalEgressGovernor $governor,
        private readonly SvrsPortalEgressConfig $egressConfig,
    ) {}

    public function nominalDailyExchanges(): int
    {
        // Prefer budgets do governador; fallback config da change
        try {
            return max(0, $this->egressConfig->maxExchangesPerDay());
        } catch (\Throwable) {
            return max(0, (int) config('outbound_deadline.nominal_daily_exchanges', 50));
        }
    }

    public function safeDailyExchanges(): int
    {
        $fraction = (float) config('outbound_deadline.auto_queue_capacity_fraction', 0.60);
        $fraction = min(1.0, max(0.0, $fraction));

        $nominal = $this->nominalDailyExchanges();
        try {
            if (! $this->governor->isCallAllowed(false)) {
                return 0;
            }
            $health = $this->governor->cohortHealth();
            $remaining = (int) ($health['exchanges_day_remaining'] ?? $nominal);
            $nominal = min($nominal, max(0, $remaining + (int) ($health['exchanges_day'] ?? 0)));
            // Para projeção do dia, usa orçamento diário configurado (não o remaining pontual)
            $nominal = $this->egressConfig->maxExchangesPerDay();
            if (($health['state'] ?? '') === 'open') {
                return 0;
            }
        } catch (\Throwable) {
            // fail-closed: sem governador saudável, zero capacidade automática
            return 0;
        }

        return (int) floor($nominal * $fraction);
    }

    public function project(
        Competence $competence,
        int $eligibleFirstAttempts,
        int $eligibleSecondAttempts,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $untilTarget = null,
        ?int $officeId = null,
    ): array {
        $from = ($from ?? CarbonImmutable::now('UTC'))->utc();
        $untilTarget = $untilTarget?->utc();

        try {
            $perTx = $this->egressConfig->exchangesPerDownload();
        } catch (\Throwable) {
            $perTx = max(1, (int) config('outbound_deadline.exchanges_per_transaction', 2));
        }

        $demand = max(0, $eligibleFirstAttempts) * $perTx
            + max(0, $eligibleSecondAttempts) * $perTx;

        $days = 1.0;
        if ($untilTarget !== null && $untilTarget->greaterThan($from)) {
            $days = max(1.0, $from->floatDiffInDays($untilTarget));
        }

        $nominalDaily = $this->nominalDailyExchanges();
        $safeDaily = $this->safeDailyExchanges();
        $safeTotal = (int) floor($safeDaily * $days);
        $nominalTotal = (int) floor($nominalDaily * $days);

        $slack = $safeTotal - $demand;
        $ratio = $safeTotal > 0 ? $demand / $safeTotal : ($demand > 0 ? INF : 0.0);
        $atRisk = $demand > $safeTotal;

        $completion = null;
        if ($safeDaily > 0 && $demand > 0) {
            $daysNeeded = (int) ceil($demand / $safeDaily);
            $completion = $from->addDays($daysNeeded);
        }

        $itemsAtRisk = 0;
        if ($atRisk && $perTx > 0) {
            $overflow = $demand - $safeTotal;
            $itemsAtRisk = (int) ceil($overflow / $perTx);
        }

        return [
            'demand_exchanges' => $demand,
            'safe_capacity_exchanges' => $safeTotal,
            'nominal_capacity_exchanges' => $nominalTotal,
            'slack_exchanges' => $slack,
            'slack_ratio' => is_finite($ratio) ? round((float) $ratio, 4) : null,
            'at_risk' => $atRisk,
            'estimated_completion_at' => $completion,
            'items_capacity_at_risk' => $itemsAtRisk,
            'office_id' => $officeId,
            'competence' => $competence->value(),
            'days_window' => round($days, 3),
            'safe_daily_exchanges' => $safeDaily,
        ];
    }
}
