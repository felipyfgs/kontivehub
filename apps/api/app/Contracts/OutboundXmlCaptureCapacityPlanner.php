<?php

namespace App\Contracts;

use App\Domain\Outbound\Competence;
use Carbon\CarbonImmutable;

/**
 * Planejador de capacidade segura (sem PFX, sem reserva de egress).
 *
 * @phpstan-type CapacityProjection array{
 *   demand_exchanges: int,
 *   safe_capacity_exchanges: int,
 *   nominal_capacity_exchanges: int,
 *   slack_exchanges: int,
 *   slack_ratio: float|null,
 *   at_risk: bool,
 *   estimated_completion_at: ?CarbonImmutable,
 *   items_capacity_at_risk: int
 * }
 */
interface OutboundXmlCaptureCapacityPlanner
{
    /**
     * Capacidade nominal da janela (exchanges), lida do governador/adapter.
     */
    public function nominalDailyExchanges(): int;

    /**
     * Capacidade segura para auto-queue (fração configurável, default 60%).
     */
    public function safeDailyExchanges(): int;

    /**
     * @return CapacityProjection
     */
    public function project(
        Competence $competence,
        int $eligibleFirstAttempts,
        int $eligibleSecondAttempts,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $untilTarget = null,
        ?int $officeId = null,
    ): array;
}
