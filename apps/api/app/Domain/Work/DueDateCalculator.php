<?php

namespace App\Domain\Work;

use App\Enums\Work\DueRuleType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Calculador puro de prazos civis no timezone do escritório.
 * Sem feriados/dias úteis no MVP.
 */
final class DueDateCalculator
{
    /**
     * Calcula data civil Y-m-d a partir da regra.
     *
     * @throws InvalidArgumentException quando a regra depende de prazo do processo e ele falta
     */
    public function calculate(
        DueRule $rule,
        CompetenceMonth $competence,
        string $tenantTimezone,
        ?string $processDueDate = null,
    ): string {
        $tz = $this->assertTimezone($tenantTimezone);

        return match ($rule->type) {
            DueRuleType::FixedDayOfCompetence => $this->fixedDay($competence, $rule->value, $tz),
            DueRuleType::DaysAfterCompetenceStart => $this->daysAfterCompetenceStart($competence, $rule->value, $tz),
            DueRuleType::DaysBeforeProcessDue => $this->daysBeforeProcessDue($processDueDate, $rule->value, $tz),
        };
    }

    public function assertTimezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (\Exception) {
            throw new InvalidArgumentException("Timezone IANA inválido: {$timezone}");
        }
    }

    /**
     * Data civil “hoje” no timezone do escritório (Y-m-d).
     */
    public function todayInTenant(string $tenantTimezone, ?DateTimeImmutable $now = null): string
    {
        $tz = $this->assertTimezone($tenantTimezone);
        $now ??= new DateTimeImmutable('now', $tz);

        return $now->setTimezone($tz)->format('Y-m-d');
    }

    private function fixedDay(CompetenceMonth $competence, int $day, DateTimeZone $tz): string
    {
        $start = new DateTimeImmutable($competence->startDate(), $tz);
        $lastDay = (int) $start->format('t');
        $clamped = min($day, $lastDay);

        return $start->setDate($competence->year, $competence->month, $clamped)->format('Y-m-d');
    }

    private function daysAfterCompetenceStart(CompetenceMonth $competence, int $days, DateTimeZone $tz): string
    {
        $start = new DateTimeImmutable($competence->startDate(), $tz);

        return $start->modify("+{$days} days")->format('Y-m-d');
    }

    private function daysBeforeProcessDue(?string $processDueDate, int $days, DateTimeZone $tz): string
    {
        if ($processDueDate === null || $processDueDate === '') {
            throw new InvalidArgumentException(
                'Regra DAYS_BEFORE_PROCESS_DUE exige prazo do processo calculável.',
            );
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $processDueDate)) {
            throw new InvalidArgumentException('Prazo do processo inválido (use Y-m-d).');
        }

        $due = DateTimeImmutable::createFromFormat('!Y-m-d', $processDueDate, $tz);
        if ($due === false || $due->format('Y-m-d') !== $processDueDate) {
            throw new InvalidArgumentException('Prazo do processo inválido (use Y-m-d).');
        }

        return $due->modify("-{$days} days")->format('Y-m-d');
    }
}
