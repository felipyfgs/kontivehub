<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use Carbon\CarbonImmutable;

/** Aplica ajuste bancário usando fins de semana e feriados da versão do calendário. */
final class PgdasdBankingCalendar
{
    public const ADJUSTMENT = 'NEXT_BANKING_BUSINESS_DAY';

    /**
     * @param  list<string>  $nonBusinessDates  Datas ISO (Y-m-d) da fonte versionada.
     */
    public function nextBankingBusinessDay(
        CarbonImmutable $date,
        array $nonBusinessDates = [],
    ): CarbonImmutable {
        $time = [$date->hour, $date->minute, $date->second];
        $blocked = array_fill_keys(array_filter(
            $nonBusinessDates,
            static fn (mixed $item): bool => is_string($item) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $item) === 1,
        ), true);
        $cursor = $date;
        while ($cursor->isWeekend() || isset($blocked[$cursor->format('Y-m-d')])) {
            $cursor = $cursor->addDay();
        }

        return $cursor->setTime(...$time);
    }

    /**
     * @param  list<string>  $nonBusinessDates
     * @return array{date:CarbonImmutable,verified:bool,reason:string}
     */
    public function applyAdjustment(
        CarbonImmutable $rawDue,
        string $adjustment,
        array $nonBusinessDates = [],
        bool $officialCalendarVerified = false,
    ): array {
        $normalized = strtoupper(trim($adjustment));
        if ($normalized === '' || $normalized === 'NONE') {
            return [
                'date' => $rawDue,
                'verified' => $officialCalendarVerified,
                'reason' => 'NO_ADJUSTMENT',
            ];
        }
        if (! in_array($normalized, [self::ADJUSTMENT, 'NEXT_BUSINESS_DAY'], true)) {
            return [
                'date' => $rawDue,
                'verified' => false,
                'reason' => 'UNKNOWN_ADJUSTMENT',
            ];
        }

        return [
            'date' => $this->nextBankingBusinessDay($rawDue, $nonBusinessDates),
            'verified' => $officialCalendarVerified,
            'reason' => $officialCalendarVerified
                ? 'VERSIONED_BANKING_CALENDAR'
                : 'WEEKEND_ONLY_UNVERIFIED_HOLIDAYS',
        ];
    }
}
