<?php

namespace App\Domain\Work;

use App\Enums\Work\RecurrenceFrequency;
use App\Enums\Work\RecurrencePeriodOffset;
use App\Models\Tenant;
use App\Support\Work\TenantTimezone;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Validação e cálculo de agenda da Rotina (dia 1–28, frequência, defasagem, fuso).
 */
final class WorkRoutineRecurrenceSchedule
{
    public const MIN_GENERATION_DAY = 1;

    public const MAX_GENERATION_DAY = 28;

    public const MAX_CATCH_UP = 36;

    public function __construct(
        public readonly bool $enabled,
        public readonly ?RecurrenceFrequency $frequency,
        public readonly int $generationDay,
        public readonly ?int $anchorMonth,
        public readonly RecurrencePeriodOffset $periodOffset,
    ) {
        $this->assertValid();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input, bool $requireEnabledConfig = false): self
    {
        $enabled = (bool) ($input['recurrence_enabled'] ?? false);
        $frequencyRaw = $input['recurrence_frequency'] ?? $input['frequency'] ?? null;
        $frequency = is_string($frequencyRaw) && $frequencyRaw !== ''
            ? RecurrenceFrequency::from($frequencyRaw)
            : null;
        $day = (int) ($input['generation_day'] ?? self::MIN_GENERATION_DAY);
        $anchor = array_key_exists('anchor_month', $input) && $input['anchor_month'] !== null
            ? (int) $input['anchor_month']
            : null;
        $offsetRaw = $input['period_offset'] ?? RecurrencePeriodOffset::Previous->value;
        $offset = RecurrencePeriodOffset::from((string) $offsetRaw);

        $schedule = new self($enabled, $frequency, $day, $anchor, $offset);

        if (($enabled || $requireEnabledConfig) && $frequency === null) {
            throw new InvalidArgumentException('Frequência é obrigatória quando a recorrência está habilitada.');
        }

        return $schedule;
    }

    public function assertValid(): void
    {
        if ($this->generationDay < self::MIN_GENERATION_DAY || $this->generationDay > self::MAX_GENERATION_DAY) {
            throw new InvalidArgumentException(
                sprintf(
                    'Dia de geração deve estar entre %d e %d.',
                    self::MIN_GENERATION_DAY,
                    self::MAX_GENERATION_DAY,
                ),
            );
        }

        if (! $this->enabled) {
            return;
        }

        if ($this->frequency === null) {
            throw new InvalidArgumentException('Frequência é obrigatória quando a recorrência está habilitada.');
        }

        $this->assertAnchorForFrequency($this->frequency, $this->anchorMonth);
    }

    public static function assertAnchorForFrequency(RecurrenceFrequency $frequency, ?int $anchorMonth): void
    {
        if ($anchorMonth === null) {
            return;
        }

        match ($frequency) {
            RecurrenceFrequency::Monthly => throw new InvalidArgumentException(
                'anchor_month não se aplica à frequência mensal.',
            ),
            RecurrenceFrequency::Quarterly => self::assertRange($anchorMonth, 1, 3, 'anchor_month trimestral'),
            RecurrenceFrequency::Yearly => self::assertRange($anchorMonth, 1, 12, 'anchor_month anual'),
        };
    }

    /**
     * @return list<int>
     */
    public function generationMonths(): array
    {
        $frequency = $this->frequency
            ?? throw new InvalidArgumentException('Frequência ausente.');

        return match ($frequency) {
            RecurrenceFrequency::Monthly => range(1, 12),
            RecurrenceFrequency::Quarterly => $this->quarterlyMonths(),
            RecurrenceFrequency::Yearly => [$this->anchorMonth ?? 1],
        };
    }

    /**
     * Próximo disparo estritamente após $afterUtc (UTC), no fuso do Escritório.
     */
    public function nextRunAtUtc(Tenant $tenant, ?CarbonImmutable $afterUtc = null): CarbonImmutable
    {
        if (! $this->enabled || $this->frequency === null) {
            throw new InvalidArgumentException('Agenda inativa não calcula próximo disparo.');
        }

        $tz = TenantTimezone::for($tenant);
        $afterUtc ??= CarbonImmutable::now('UTC');
        $cursor = $afterUtc->timezone($tz)->startOfDay()->addDay();

        for ($i = 0; $i < 400; $i++) {
            if ($this->isGenerationDay($cursor)) {
                return $cursor->setTime(0, 0, 0)->utc();
            }
            $cursor = $cursor->addDay();
        }

        throw new InvalidArgumentException('Não foi possível calcular o próximo disparo da agenda.');
    }

    /**
     * Próximo disparo a partir de agora (inclui hoje se ainda não passou meia-noite local do dia de geração).
     */
    public function upcomingRunAtUtc(Tenant $tenant, ?CarbonImmutable $nowUtc = null): CarbonImmutable
    {
        if (! $this->enabled || $this->frequency === null) {
            throw new InvalidArgumentException('Agenda inativa não calcula próximo disparo.');
        }

        $tz = TenantTimezone::for($tenant);
        $nowUtc ??= CarbonImmutable::now('UTC');
        $cursor = $nowUtc->timezone($tz)->startOfDay();

        for ($i = 0; $i < 400; $i++) {
            if ($this->isGenerationDay($cursor)) {
                $runLocal = $cursor->setTime(0, 0, 0);
                if ($runLocal->utc()->greaterThanOrEqualTo($nowUtc->startOfSecond())) {
                    return $runLocal->utc();
                }
            }
            $cursor = $cursor->addDay();
        }

        throw new InvalidArgumentException('Não foi possível calcular o próximo disparo da agenda.');
    }

    public function periodForRunLocalDate(CarbonImmutable $runLocal): ReferencePeriod
    {
        if ($this->frequency === null) {
            throw new InvalidArgumentException('Frequência ausente.');
        }

        $containing = $this->containingPeriod($runLocal);
        if ($this->periodOffset === RecurrencePeriodOffset::Previous) {
            return $containing->previous();
        }

        return $containing;
    }

    public function containingPeriod(CarbonImmutable $localDate): ReferencePeriod
    {
        $frequency = $this->frequency
            ?? throw new InvalidArgumentException('Frequência ausente.');

        return match ($frequency) {
            RecurrenceFrequency::Monthly => ReferencePeriod::monthly($localDate->year, $localDate->month),
            RecurrenceFrequency::Quarterly => $this->quarterContaining($localDate),
            RecurrenceFrequency::Yearly => ReferencePeriod::annual($localDate->year),
        };
    }

    public function isGenerationDay(CarbonImmutable $localDate): bool
    {
        if ($localDate->day !== $this->generationDay) {
            return false;
        }

        return in_array($localDate->month, $this->generationMonths(), true);
    }

    /**
     * @return array{
     *     recurrence_enabled: bool,
     *     recurrence_frequency: ?string,
     *     generation_day: int,
     *     anchor_month: ?int,
     *     period_offset: string
     * }
     */
    public function toArray(): array
    {
        return [
            'recurrence_enabled' => $this->enabled,
            'recurrence_frequency' => $this->frequency?->value,
            'generation_day' => $this->generationDay,
            'anchor_month' => $this->anchorMonth,
            'period_offset' => $this->periodOffset->value,
        ];
    }

    /**
     * @return list<int>
     */
    private function quarterlyMonths(): array
    {
        $anchor = $this->anchorMonth ?? 1;
        self::assertRange($anchor, 1, 3, 'anchor_month trimestral');

        return [$anchor, $anchor + 3, $anchor + 6, $anchor + 9];
    }

    private function quarterContaining(CarbonImmutable $localDate): ReferencePeriod
    {
        $months = $this->quarterlyMonths();
        $month = $localDate->month;
        $year = $localDate->year;

        foreach ($months as $index => $startMonth) {
            $endMonth = $startMonth + 2;
            if ($month >= $startMonth && $month <= $endMonth) {
                return ReferencePeriod::quarterly($year, $index + 1);
            }
        }

        // Antes do primeiro âncora no ano (ex.: jan com anchor=2) → Q4 do ano anterior.
        return ReferencePeriod::quarterly($year - 1, 4);
    }

    private static function assertRange(int $value, int $min, int $max, string $label): void
    {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(
                sprintf('%s deve estar entre %d e %d.', $label, $min, $max),
            );
        }
    }
}
