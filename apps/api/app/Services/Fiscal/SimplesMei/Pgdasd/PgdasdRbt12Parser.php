<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\Enums\PgdasdRbt12Status;

/**
 * Parser versionado e fail-closed do RBT12 contido no extrato/declaração PGDAS-D.
 *
 * Layouts observados:
 * - Extrato DAS (CONSEXTRATO16): rótulo completo + (RBT12) na linha seguinte
 * - Declaração (CONSDECREC15): rótulo quebrado
 *     "Receita bruta acumulada nos doze meses anteriores   MI  ME  Total"
 *     "ao PA (RBT12)"
 */
final class PgdasdRbt12Parser
{
    public const VERSION = 'pgdasd-rbt12-v4';

    /**
     * @return array{
     *   status:PgdasdRbt12Status,
     *   total_cents:?int,
     *   internal_market_cents:?int,
     *   external_market_cents:?int,
     *   rpa_cents:?int,
     *   parser_version:string,
     *   reason:?string
     * }
     */
    public function parse(string $text, string $periodoApuracao): array
    {
        if (trim($text) === '') {
            return $this->result(PgdasdRbt12Status::NotFound, reason: 'EMPTY_TEXT');
        }
        if (preg_match('/^\d{6}$/', $periodoApuracao) !== 1) {
            return $this->result(PgdasdRbt12Status::Failed, reason: 'INVALID_EXPECTED_PERIOD');
        }
        if (! $this->containsExpectedPeriod($text, $periodoApuracao)) {
            return $this->result(PgdasdRbt12Status::Ambiguous, reason: 'PERIOD_MISMATCH');
        }

        $lines = preg_split('/\R/u', $text) ?: [];
        $totalCandidates = [];
        $internalCandidates = [];
        $externalCandidates = [];

        foreach ($lines as $index => $line) {
            $block = $this->extractRbt12At($lines, $index);
            if ($block === null) {
                continue;
            }
            if ($block['internal'] !== null) {
                $internalCandidates[] = $block['internal'];
            }
            if ($block['external'] !== null) {
                $externalCandidates[] = $block['external'];
            }
            if ($block['total'] !== null) {
                $totalCandidates[] = $block['total'];
            }
        }

        $totals = array_values(array_unique($totalCandidates));
        $internals = array_values(array_unique($internalCandidates));
        $externals = array_values(array_unique($externalCandidates));
        $rpa = $this->parseRpaCents($lines);

        if (count($totals) > 1 || count($internals) > 1 || count($externals) > 1) {
            return $this->result(PgdasdRbt12Status::Ambiguous, reason: 'CONFLICTING_VALUES', rpa: $rpa);
        }

        $internal = $internals[0] ?? null;
        $external = $externals[0] ?? null;
        $total = $totals[0] ?? null;
        if ($total === null && $internal !== null && $external !== null) {
            $total = $internal + $external;
        }
        if ($total === null) {
            return $this->result(PgdasdRbt12Status::NotFound, reason: 'EXACT_RBT12_VALUE_NOT_FOUND', rpa: $rpa);
        }
        if ($internal !== null && $external !== null && $internal + $external !== $total) {
            return $this->result(PgdasdRbt12Status::Ambiguous, reason: 'COMPOSITION_MISMATCH', rpa: $rpa);
        }

        return $this->result(PgdasdRbt12Status::Parsed, $total, $internal, $external, rpa: $rpa);
    }

    /**
     * @param  list<string>  $lines
     * @return array{internal:?int,external:?int,total:?int}|null
     */
    private function extractRbt12At(array $lines, int $index): ?array
    {
        $line = $lines[$index] ?? '';

        // Marcador sozinho "(RBT12)" ou continuação "ao PA (RBT12)" → valores na linha anterior.
        if ($this->isBareRbt12Marker($line) || $this->isSplitRbt12Marker($line)) {
            $prev = $lines[$index - 1] ?? '';
            $next = $lines[$index + 1] ?? '';
            if ($this->isProportionalizedLine($prev) || $this->isProportionalizedLine($next) || $this->isProportionalizedLine($line)) {
                return null;
            }
            if ($this->isAccumulatedTwelveMonthsLabel($prev)) {
                return $this->compositionFromMoneyLine($prev);
            }

            return null;
        }

        // Linha descritiva com valores, seguida do marcador (RBT12) / "ao PA (RBT12)".
        if ($this->isAccumulatedTwelveMonthsLabel($line)) {
            $next = $lines[$index + 1] ?? '';
            if ($this->isProportionalizedLine($line) || $this->isProportionalizedLine($next)) {
                return null;
            }
            if ($this->isBareRbt12Marker($next) || $this->isSplitRbt12Marker($next) || $this->containsRbt12Token($next)) {
                return $this->compositionFromMoneyLine($line);
            }
        }

        // Linha única legada: token RBT12/RB12 + montantes na mesma linha.
        if ($this->containsRbt12Token($line) && ! $this->isBareRbt12Marker($line)) {
            if ($this->isProportionalizedLine($line)) {
                return null;
            }
            $internal = $this->moneyAfterLabel($line, '/mercado\s+interno/iu');
            $external = $this->moneyAfterLabel($line, '/mercado\s+externo/iu');
            $total = $this->moneyAfterLabel($line, '/\btotal\b/iu');
            $values = $this->moneyValues($line);
            if ($total === null && count($values) === 1) {
                $total = $values[0];
            } elseif ($total === null && count($values) >= 3) {
                $internal ??= $values[0];
                $external ??= $values[1];
                $total = $values[2];
            }
            if ($total !== null || $internal !== null || $external !== null) {
                return [
                    'internal' => $internal,
                    'external' => $external,
                    'total' => $total,
                ];
            }
        }

        return null;
    }

    private function isBareRbt12Marker(string $line): bool
    {
        return preg_match('/^\s*\(?\s*RBT\s*12\s*\)?\s*$/iu', $line) === 1;
    }

    /** Continuação do rótulo na declaração: "ao PA (RBT12)". */
    private function isSplitRbt12Marker(string $line): bool
    {
        return preg_match('/^\s*ao\s+PA\s*\(\s*RBT\s*12\s*\)\s*$/iu', $line) === 1;
    }

    private function containsRbt12Token(string $line): bool
    {
        return preg_match('/\bRBT\s*12\b/iu', $line) === 1
            || preg_match('/\bRB\s*12\b/iu', $line) === 1;
    }

    private function isAccumulatedTwelveMonthsLabel(string $line): bool
    {
        // Extrato: "... anteriores ao PA" na mesma linha.
        // Declaração: "... anteriores" com valores; "ao PA (RBT12)" na linha seguinte.
        return preg_match(
            '/receita\s+bruta\s+acumulada\s+nos\s+(doze|12)\s+meses\s+anteriores(?:\s+ao\s+PA)?/iu',
            $line
        ) === 1;
    }

    private function isProportionalizedLine(string $line): bool
    {
        return preg_match('/proporcionalizad[oa]|\bRBT\s*12\s*p\b/iu', $line) === 1;
    }

    /**
     * @return array{internal:?int,external:?int,total:?int}|null
     */
    private function compositionFromMoneyLine(string $line): ?array
    {
        $values = $this->moneyValues($line);
        if (count($values) < 3) {
            $total = count($values) === 1 ? $values[0] : null;
            if ($total === null) {
                return null;
            }

            return ['internal' => null, 'external' => null, 'total' => $total];
        }

        return [
            'internal' => $values[0],
            'external' => $values[1],
            'total' => $values[2],
        ];
    }

    /**
     * @param  list<string>  $lines
     */
    private function parseRpaCents(array $lines): ?int
    {
        $candidates = [];
        foreach ($lines as $line) {
            if (preg_match('/\bRPA\b/u', $line) !== 1
                && preg_match('/receita\s+bruta\s+do\s+PA/iu', $line) !== 1
                && preg_match('/receita\s+bruta\s+do\s+per[ií]odo/iu', $line) !== 1) {
                continue;
            }
            if ($this->isAccumulatedTwelveMonthsLabel($line) || $this->containsRbt12Token($line)) {
                continue;
            }
            $values = $this->moneyValues($line);
            if (count($values) >= 3) {
                $candidates[] = $values[2];
            } elseif (count($values) === 1) {
                $candidates[] = $values[0];
            }
        }
        $unique = array_values(array_unique($candidates));

        return count($unique) === 1 ? $unique[0] : null;
    }

    private function containsExpectedPeriod(string $text, string $pa): bool
    {
        $year = substr($pa, 0, 4);
        $month = substr($pa, 4, 2);
        foreach ([$pa, "{$month}/{$year}", "{$year}/{$month}", "{$month}-{$year}", "{$year}-{$month}"] as $candidate) {
            if (str_contains($text, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function moneyAfterLabel(string $line, string $labelPattern): ?int
    {
        if (preg_match($labelPattern, $line, $label, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $tail = substr($line, $label[0][1] + strlen($label[0][0]));
        $values = $this->moneyValues($tail);

        return $values[0] ?? null;
    }

    /** @return list<int> */
    private function moneyValues(string $value): array
    {
        preg_match_all('/(?<!\d)(?:R\$\s*)?(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2}|\d+\.\d{2})(?!\d)/u', $value, $matches);
        $out = [];
        foreach ($matches[1] ?? [] as $raw) {
            $cents = $this->toCents((string) $raw);
            if ($cents !== null) {
                $out[] = $cents;
            }
        }

        return $out;
    }

    private function toCents(string $value): ?int
    {
        $value = trim($value);
        if (str_contains($value, ',')) {
            [$integer, $decimal] = array_pad(explode(',', $value, 2), 2, '00');
            $integer = str_replace('.', '', $integer);
        } else {
            [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '00');
        }
        if (preg_match('/^\d+$/', $integer) !== 1 || preg_match('/^\d{2}$/', $decimal) !== 1) {
            return null;
        }
        $cents = ltrim($integer, '0').$decimal;
        $cents = ltrim($cents, '0');
        if ($cents === '') {
            return 0;
        }
        if (strlen($cents) > 18 || (strlen($cents) === 18 && strcmp($cents, (string) PHP_INT_MAX) > 0)) {
            return null;
        }

        return (int) $cents;
    }

    /**
     * @return array{
     *   status:PgdasdRbt12Status,
     *   total_cents:?int,
     *   internal_market_cents:?int,
     *   external_market_cents:?int,
     *   rpa_cents:?int,
     *   parser_version:string,
     *   reason:?string
     * }
     */
    private function result(
        PgdasdRbt12Status $status,
        ?int $total = null,
        ?int $internal = null,
        ?int $external = null,
        ?string $reason = null,
        ?int $rpa = null,
    ): array {
        return [
            'status' => $status,
            'total_cents' => $total,
            'internal_market_cents' => $internal,
            'external_market_cents' => $external,
            'rpa_cents' => $rpa,
            'parser_version' => self::VERSION,
            'reason' => $reason,
        ];
    }
}
