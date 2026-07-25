<?php

namespace App\Services\Outbound;

use App\Enums\OutboundUrgencyBand;
use Illuminate\Support\Collection;

/**
 * Seleção justa: EDF por due_at/faixa, no máximo 1 por raiz por rodada.
 */
final class OutboundDeadlineFairQueue
{
    private const BAND_ORDER = [
        OutboundUrgencyBand::Overdue->value => 0,
        OutboundUrgencyBand::Contingency->value => 1,
        OutboundUrgencyBand::Attention->value => 2,
        OutboundUrgencyBand::Planned->value => 3,
        OutboundUrgencyBand::Captured->value => 9,
    ];

    /**
     * @param  Collection<int, object>  $items  objetos com office_id, root_cnpj, model, due_at, urgency_band, access_key, authorized_at?, svrs_transaction_count
     * @return list<object>
     */
    public function order(Collection $items): array
    {
        return $items->sort(function ($a, $b): int {
            $bandA = self::BAND_ORDER[$this->scalar($a->urgency_band ?? null) ?: 'PLANNED'] ?? 5;
            $bandB = self::BAND_ORDER[$this->scalar($b->urgency_band ?? null) ?: 'PLANNED'] ?? 5;
            if ($bandA !== $bandB) {
                return $bandA <=> $bandB;
            }
            // primeiras tentativas antes de segundas
            $txA = (int) ($a->svrs_transaction_count ?? 0);
            $txB = (int) ($b->svrs_transaction_count ?? 0);
            if ($txA !== $txB) {
                return $txA <=> $txB;
            }
            $dueA = $this->scalar($a->due_at ?? null);
            $dueB = $this->scalar($b->due_at ?? null);
            if ($dueA !== $dueB) {
                return $dueA <=> $dueB;
            }
            $authA = $this->scalar($a->authorized_at ?? null);
            $authB = $this->scalar($b->authorized_at ?? null);
            if ($authA !== $authB) {
                return $authA <=> $authB;
            }

            return strcmp($this->scalar($a->access_key ?? null), $this->scalar($b->access_key ?? null));
        })->values()->all();
    }

    /**
     * Round-robin por raiz: no máximo um item por root_cnpj por rodada.
     *
     * @param  list<object>  $ordered
     * @return list<object>
     */
    public function fairSelect(array $ordered, int $limit): array
    {
        $selected = [];
        $remaining = $ordered;

        while (count($selected) < $limit && $remaining !== []) {
            $seenRoots = [];
            $nextRemaining = [];
            foreach ($remaining as $item) {
                if (count($selected) >= $limit) {
                    $nextRemaining[] = $item;

                    continue;
                }
                $root = $this->scalar($item->root_cnpj ?? null)
                    .'|'.$this->scalar($item->office_id ?? null)
                    .'|'.$this->scalar($item->model ?? null);
                if (isset($seenRoots[$root])) {
                    $nextRemaining[] = $item;

                    continue;
                }
                $seenRoots[$root] = true;
                $selected[] = $item;
            }
            if ($nextRemaining === $remaining) {
                // sem progresso
                break;
            }
            $remaining = $nextRemaining;
        }

        return $selected;
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        return (string) $value;
    }

    /**
     * Spread determinístico de next_attempt_at a partir de base + offset estável.
     */
    public function spreadSeconds(string $stableKey, int $windowSeconds): int
    {
        if ($windowSeconds <= 0) {
            return 0;
        }
        $h = crc32($stableKey);

        return $h % $windowSeconds;
    }
}
