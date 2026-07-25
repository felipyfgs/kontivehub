<?php

namespace App\Services\Serpro\Usage;

use App\Models\SerproBillingCycle;
use Illuminate\Support\Carbon;

/**
 * Ciclo contratual de apuração 21–20 (Brasília):
 * do dia 21 do mês anterior ao dia 20 do mês de referência inclusivo.
 *
 * Separado do mês calendário usado em relatórios legados.
 */
final class BillingCycleResolver
{
    public const KIND = 'D21_D20';

    /**
     * @return array{
     *   cycle_code: string,
     *   period_start: Carbon,
     *   period_end: Carbon,
     *   label: string,
     *   kind: string
     * }
     */
    public function resolve(Carbon|string|null $at = null): array
    {
        $at = $this->asBrasilia($at);
        [$start, $end] = $this->boundsFor($at);

        $code = $this->codeFor($start, $end);

        return [
            'cycle_code' => $code,
            'period_start' => $start,
            'period_end' => $end,
            'label' => sprintf(
                'Ciclo %s a %s',
                $start->format('d/m/Y'),
                $end->format('d/m/Y'),
            ),
            'kind' => self::KIND,
        ];
    }

    /**
     * Garante linha em serpro_billing_cycles (idempotente).
     */
    public function ensurePersisted(Carbon|string|null $at = null): SerproBillingCycle
    {
        $resolved = $this->resolve($at);

        return SerproBillingCycle::query()->firstOrCreate(
            ['cycle_code' => $resolved['cycle_code']],
            [
                'period_start' => $resolved['period_start']->toDateString(),
                'period_end' => $resolved['period_end']->toDateString(),
                'label' => $resolved['label'],
                'status' => 'OPEN',
                'metadata' => ['kind' => self::KIND],
            ],
        );
    }

    /**
     * @return array{0: Carbon, 1: Carbon} start, end (início/fim do dia em Brasília)
     */
    public function boundsFor(Carbon $at): array
    {
        $local = $this->asBrasilia($at);

        // Se dia >= 21: ciclo começa neste mês dia 21 e termina no próximo mês dia 20.
        // Se dia <= 20: ciclo começa no mês anterior dia 21 e termina neste mês dia 20.
        if ($local->day >= 21) {
            $start = $local->copy()->startOfMonth()->day(21)->startOfDay();
            $end = $local->copy()->addMonthNoOverflow()->startOfMonth()->day(20)->endOfDay();
        } else {
            $start = $local->copy()->subMonthNoOverflow()->startOfMonth()->day(21)->startOfDay();
            $end = $local->copy()->startOfMonth()->day(20)->endOfDay();
        }

        return [$start, $end];
    }

    public function codeFor(Carbon $start, Carbon $end): string
    {
        return sprintf(
            'D21D20-%s-%s',
            $start->format('Ymd'),
            $end->format('Ymd'),
        );
    }

    /**
     * Mês calendário correspondente ao fim do ciclo (para rótulos legados).
     *
     * @return array{year: int, month: int}
     */
    public function calendarMonthOfCycleEnd(Carbon|string|null $at = null): array
    {
        $end = $this->resolve($at)['period_end'];

        return ['year' => (int) $end->year, 'month' => (int) $end->month];
    }

    private function asBrasilia(Carbon|string|null $at): Carbon
    {
        $tz = config('serpro_usage.billing_timezone', 'America/Sao_Paulo');
        if ($at instanceof Carbon) {
            return $at->copy()->timezone($tz);
        }
        if (is_string($at) && $at !== '') {
            return Carbon::parse($at, $tz);
        }

        return now($tz);
    }
}
