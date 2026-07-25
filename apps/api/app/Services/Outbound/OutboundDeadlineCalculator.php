<?php

namespace App\Services\Outbound;

use App\Domain\Outbound\Competence;
use App\Domain\Outbound\DeadlinePlan;
use App\Domain\Outbound\OperationalSla;
use App\Enums\OutboundDeadlineSource;
use App\Enums\OutboundUrgencyBand;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Cálculo de due_at/target_at e faixas de urgência (sem rede, sem PFX).
 */
final class OutboundDeadlineCalculator
{
    public function planFromAuthorizationDate(
        DateTimeInterface $authorizedAt,
        ?string $officeTimezone = null,
        ?CarbonImmutable $now = null,
        bool $captured = false,
        bool $capacityAtRisk = false,
    ): DeadlinePlan {
        $localTz = $officeTimezone ?: (string) config('outbound_deadline.timezone', 'America/Sao_Paulo');
        $authLocal = CarbonImmutable::instance($authorizedAt)->timezone($localTz);
        $competence = Competence::fromYearMonth($authLocal->year, $authLocal->month);
        $sla = OperationalSla::fromConfig($officeTimezone);
        $deadlines = $sla->deadlinesFor($competence);
        $now = $now?->utc() ?? CarbonImmutable::now('UTC');

        return new DeadlinePlan(
            competence: $competence,
            dueAt: $deadlines['due_at'],
            targetAt: $deadlines['target_at'],
            source: OutboundDeadlineSource::Authorization,
            band: $this->band($deadlines['due_at'], $deadlines['target_at'], $now, $captured, $capacityAtRisk),
            provisional: false,
        );
    }

    public function planFromAccessKey(
        string $accessKey,
        ?string $officeTimezone = null,
        ?CarbonImmutable $now = null,
        bool $captured = false,
        bool $capacityAtRisk = false,
    ): ?DeadlinePlan {
        $competence = Competence::tryFromAccessKey($accessKey);
        if ($competence === null) {
            return null;
        }
        $sla = OperationalSla::fromConfig($officeTimezone);
        $deadlines = $sla->deadlinesFor($competence);
        $now = $now?->utc() ?? CarbonImmutable::now('UTC');

        return new DeadlinePlan(
            competence: $competence,
            dueAt: $deadlines['due_at'],
            targetAt: $deadlines['target_at'],
            source: OutboundDeadlineSource::AccessKeyYm,
            band: $this->band($deadlines['due_at'], $deadlines['target_at'], $now, $captured, $capacityAtRisk),
            provisional: true,
        );
    }

    public function band(
        CarbonImmutable $dueAt,
        CarbonImmutable $targetAt,
        CarbonImmutable $now,
        bool $captured = false,
        bool $capacityAtRisk = false,
    ): OutboundUrgencyBand {
        if ($captured) {
            return OutboundUrgencyBand::Captured;
        }

        $now = $now->utc();
        $dueAt = $dueAt->utc();
        $targetAt = $targetAt->utc();

        if ($now->greaterThan($dueAt)) {
            return OutboundUrgencyBand::Overdue;
        }

        $contingencyHours = (int) config('outbound_deadline.contingency_hours_before_due', 72);
        if ($now->greaterThanOrEqualTo($targetAt)
            || $now->diffInHours($dueAt, false) <= $contingencyHours
            || $capacityAtRisk) {
            return OutboundUrgencyBand::Contingency;
        }

        $attentionDays = (int) config('outbound_deadline.attention_days', 7);
        if ($now->diffInDays($targetAt, false) <= $attentionDays) {
            return OutboundUrgencyBand::Attention;
        }

        return OutboundUrgencyBand::Planned;
    }

    /**
     * Janela de acomodação (horas) antes de consumir SVRS.
     */
    public function accommodationHours(OutboundUrgencyBand $band, CarbonImmutable $targetAt, ?CarbonImmutable $now = null): int
    {
        if (in_array($band, [OutboundUrgencyBand::Contingency, OutboundUrgencyBand::Overdue, OutboundUrgencyBand::Captured], true)) {
            return 0;
        }

        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $attentionDays = (int) config('outbound_deadline.attention_days', 7);
        $default = (int) config('outbound_deadline.accommodation_hours', 24);
        $short = (int) config('outbound_deadline.accommodation_short_hours', 6);

        if ($now->diffInDays($targetAt->utc(), false) < $attentionDays) {
            return $short;
        }

        return $default;
    }

    public function discoveredAfterDue(CarbonImmutable $discoveredAt, CarbonImmutable $dueAt): bool
    {
        return $discoveredAt->utc()->greaterThan($dueAt->utc());
    }
}
