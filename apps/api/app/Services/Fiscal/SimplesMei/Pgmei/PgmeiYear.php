<?php

namespace App\Services\Fiscal\SimplesMei\Pgmei;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Utilitários de ano calendário para PGMEI/DIVIDAATIVA24. */
final class PgmeiYear
{
    public const WINDOW = 5;

    public static function assertValid(int|string $year): int
    {
        $y = (int) $year;
        if ($y < 2000 || $y > 2100) {
            throw new InvalidArgumentException('anoCalendario deve estar entre 2000 e 2100.');
        }

        return $y;
    }

    public static function assertFormat(string $anoCalendario): string
    {
        $ano = trim($anoCalendario);
        if (preg_match('/^\d{4}$/', $ano) !== 1) {
            throw new InvalidArgumentException('anoCalendario deve ter 4 dígitos (AAAA).');
        }
        self::assertValid($ano);

        return $ano;
    }

    /**
     * Cinco anos mais recentes: ano corrente e quatro anteriores (fuso do escritório).
     *
     * @return list<int>
     */
    public static function recentYears(?CarbonImmutable $now = null, string $timezone = 'America/Sao_Paulo'): array
    {
        $local = ($now ?? CarbonImmutable::now())->timezone($timezone);
        $current = (int) $local->year;
        $years = [];
        for ($i = 0; $i < self::WINDOW; $i++) {
            $years[] = $current - $i;
        }

        return $years;
    }

    /**
     * Rotação determinística diária: em 5 ciclos sucessivos cada ano da janela é selecionado uma vez.
     */
    public static function yearForDailyCycle(
        ?CarbonImmutable $now = null,
        string $timezone = 'America/Sao_Paulo',
    ): int {
        $local = ($now ?? CarbonImmutable::now())->timezone($timezone);
        $years = self::recentYears($local, $timezone);
        $index = ((int) $local->format('z')) % self::WINDOW;

        return $years[$index];
    }

    public static function toPeriodKey(int $year): string
    {
        return sprintf('%04d', self::assertValid($year));
    }
}
