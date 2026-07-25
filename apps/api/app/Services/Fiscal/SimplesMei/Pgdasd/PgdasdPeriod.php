<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Helpers de período de apuração (PA) PGDAS-D.
 */
final class PgdasdPeriod
{
    /**
     * PA esperado = mês anterior no fuso do escritório (janeiro → dezembro do ano anterior).
     */
    public static function expectedPa(?CarbonImmutable $now = null, string $timezone = 'America/Sao_Paulo'): CarbonImmutable
    {
        $now ??= CarbonImmutable::now($timezone);

        return $now->setTimezone($timezone)->startOfMonth()->subMonth();
    }

    public static function toPeriodoApuracao(CarbonImmutable $pa): string
    {
        return $pa->format('Ym');
    }

    public static function toPeriodKey(CarbonImmutable $pa): string
    {
        return $pa->format('Y-m');
    }

    public static function yearFromPa(CarbonImmutable $pa): string
    {
        return $pa->format('Y');
    }

    /**
     * Converte AAAAMM ou YYYY-MM em CarbonImmutable (dia 1).
     */
    public static function parse(string $value, string $timezone = 'America/Sao_Paulo'): CarbonImmutable
    {
        $value = trim($value);
        if (preg_match('/^\d{6}$/', $value) === 1) {
            $year = (int) substr($value, 0, 4);
            $month = (int) substr($value, 4, 2);
        } elseif (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            [$year, $month] = array_map('intval', explode('-', $value));
        } else {
            throw new InvalidArgumentException("Período de apuração inválido: {$value}");
        }

        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            throw new InvalidArgumentException("Período de apuração fora da faixa: {$value}");
        }

        return CarbonImmutable::create($year, $month, 1, 0, 0, 0, $timezone);
    }

    public static function periodoApuracaoFromPeriodKey(string $periodKey): string
    {
        return self::toPeriodoApuracao(self::parse($periodKey));
    }

    public static function periodKeyFromPeriodoApuracao(string $periodoApuracao): string
    {
        return self::toPeriodKey(self::parse($periodoApuracao));
    }
}
