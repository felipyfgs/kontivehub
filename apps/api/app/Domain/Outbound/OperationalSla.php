<?php

namespace App\Domain\Outbound;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * SLA operacional do escritório (não é prazo legal).
 * due_at = dia N do mês seguinte à competência, no fuso do escritório.
 */
final readonly class OperationalSla
{
    public function __construct(
        public string $timezone,
        public int $dueDay,
        public string $dueTime,
        public int $targetBufferHours,
    ) {
        if ($targetBufferHours < 24) {
            throw new InvalidArgumentException('Buffer interno do SLA não pode ser inferior a 24 horas.');
        }
        if ($dueDay < 1 || $dueDay > 28) {
            throw new InvalidArgumentException('Dia do SLA deve estar entre 1 e 28 no MVP.');
        }
        try {
            new DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Timezone do SLA inválido: '.$timezone);
        }
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $dueTime)) {
            throw new InvalidArgumentException('due_time deve ser HH:MM:SS.');
        }
    }

    public static function fromConfig(?string $tenantTimezone = null): self
    {
        $tz = $tenantTimezone ?: (string) config('outbound_deadline.timezone', 'America/Sao_Paulo');
        $buffer = (int) config('outbound_deadline.target_buffer_hours', 48);
        if ($buffer < 24) {
            $buffer = 24;
        }

        return new self(
            timezone: $tz,
            dueDay: (int) config('outbound_deadline.due_day', 1),
            dueTime: (string) config('outbound_deadline.due_time', '23:59:59'),
            targetBufferHours: $buffer,
        );
    }

    /**
     * @return array{due_at: CarbonImmutable, target_at: CarbonImmutable}
     */
    public function deadlinesFor(Competence $competence): array
    {
        $next = $competence->nextMonth();
        [$h, $i, $s] = array_map('intval', explode(':', $this->dueTime));
        $local = CarbonImmutable::create(
            $next->year,
            $next->month,
            $this->dueDay,
            $h,
            $i,
            $s,
            $this->timezone,
        );
        if ($local === null) {
            throw new InvalidArgumentException('Não foi possível calcular due_at.');
        }

        $dueUtc = $local->utc();
        $targetUtc = $dueUtc->subHours($this->targetBufferHours);

        return [
            'due_at' => $dueUtc,
            'target_at' => $targetUtc,
        ];
    }
}
