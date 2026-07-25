<?php

namespace App\Services\Usage;

use App\Models\OfficeSubscription;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Período comercial da assinatura (aniversário), NÃO mês-calendário nem ciclo SERPRO 21–20.
 *
 * Usa current_period_starts_at / current_period_ends_at; renova sem rollover de unidades.
 */
final class SubscriptionPeriodService
{
    /**
     * @return array{
     *   period_key: string,
     *   starts_at: CarbonImmutable,
     *   ends_at: CarbonImmutable
     * }
     */
    public function resolve(OfficeSubscription $subscription, CarbonImmutable|string|null $at = null): array
    {
        $subscription = $this->ensureCurrent($subscription, $at);

        $starts = CarbonImmutable::parse($subscription->current_period_starts_at);
        $ends = CarbonImmutable::parse($subscription->current_period_ends_at);

        return [
            'period_key' => $starts->toDateString(),
            'starts_at' => $starts,
            'ends_at' => $ends,
        ];
    }

    /**
     * Avança períodos vencidos até cobrir $at (sem rollover — só janela corrente).
     */
    public function ensureCurrent(
        OfficeSubscription $subscription,
        CarbonImmutable|string|null $at = null,
    ): OfficeSubscription {
        $at = $this->asImmutable($at);

        if ($subscription->current_period_starts_at === null || $subscription->current_period_ends_at === null) {
            $start = $at;
            $end = $this->periodEndFromStart($start);
            $subscription->forceFill([
                'current_period_starts_at' => $start,
                'current_period_ends_at' => $end,
            ])->save();

            return $subscription->refresh();
        }

        $ends = CarbonImmutable::parse($subscription->current_period_ends_at);
        $guard = 0;

        while ($at->greaterThan($ends)) {
            $guard++;
            if ($guard > 120) {
                throw new InvalidArgumentException('Não foi possível renovar período da assinatura (loop de renovação).');
            }

            $newStart = $ends->addSecond();
            // Preferir aniversário a partir do início original do período corrente.
            $prevStart = CarbonImmutable::parse($subscription->current_period_starts_at);
            $candidateStart = $prevStart->addMonthNoOverflow();
            if ($candidateStart->lessThanOrEqualTo($ends)) {
                $candidateStart = $newStart;
            }
            $newEnd = $this->periodEndFromStart($candidateStart);

            $subscription->forceFill([
                'current_period_starts_at' => $candidateStart,
                'current_period_ends_at' => $newEnd,
            ])->save();

            $ends = $newEnd;
        }

        return $subscription->refresh();
    }

    /**
     * Início/fim do primeiro período a partir de $from (aniversário comercial).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function initialBounds(CarbonImmutable|string|null $from = null): array
    {
        $start = $this->asImmutable($from);

        return [$start, $this->periodEndFromStart($start)];
    }

    public function periodEndFromStart(CarbonImmutable $start): CarbonImmutable
    {
        return $start->addMonthNoOverflow()->subSecond();
    }

    public function periodKey(CarbonImmutable $startsAt): string
    {
        return $startsAt->toDateString();
    }

    private function asImmutable(CarbonImmutable|string|null $at): CarbonImmutable
    {
        if ($at instanceof CarbonImmutable) {
            return $at;
        }

        if (is_string($at) && $at !== '') {
            return CarbonImmutable::parse($at);
        }

        return CarbonImmutable::now();
    }
}
