<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

/**
 * Parser fail-closed do Total do DAS na seção 6 do extrato CONSEXTRATO16.
 *
 * Layout típico (pdftotext -layout):
 *   6) Informações sobre DAS Gerado na apuração: …
 *   Número: 0720…   Data de Vencimento: …
 *   Principal  … Multa … Juros … Total  1.124,35
 */
final class PgdasdExtratoDasAmountParser
{
    public const VERSION = 'pgdasd-extrato-das-amount-v1';

    /**
     * @return array{
     *   ok: bool,
     *   amount_cents: ?int,
     *   das_number: ?string,
     *   parser_version: string,
     *   reason: ?string
     * }
     */
    public function parse(string $text, string $expectedDasNumber): array
    {
        $expected = trim($expectedDasNumber);
        if ($expected === '') {
            return $this->fail('EMPTY_EXPECTED_DAS');
        }
        if (trim($text) === '') {
            return $this->fail('EMPTY_TEXT');
        }

        if (! preg_match(
            '/6\)\s*Informações\s+sobre\s+DAS\s+Gerado[\s\S]{0,2500}?6\.1\)/ui',
            $text,
            $sectionMatch
        ) && ! preg_match(
            '/6\)\s*Informações\s+sobre\s+DAS\s+Gerado[\s\S]{0,2500}/ui',
            $text,
            $sectionMatch
        )) {
            return $this->fail('SECTION_6_NOT_FOUND');
        }

        $section = $sectionMatch[0];
        if (! preg_match('/Número:\s*(\d{1,17})\b/u', $section, $numberMatch)) {
            return $this->fail('DAS_NUMBER_NOT_FOUND');
        }
        $foundNumber = $numberMatch[1];
        if (! hash_equals($expected, $foundNumber)) {
            return $this->fail('DAS_NUMBER_MISMATCH');
        }

        if (! preg_match(
            '/Principal\s+[\d.]+,\d{2}\s+Multa\s+[\d.]+,\d{2}\s+Juros\s+[\d.]+,\d{2}\s+Total\s+([\d.]+,\d{2})/ui',
            $section,
            $totalMatch
        )) {
            // Fallback: último "Total X,XX" na seção 6 (antes de 6.1).
            if (! preg_match_all('/\bTotal\s+([\d.]+,\d{2})\b/ui', $section, $allTotals)
                || $allTotals[1] === []) {
                return $this->fail('TOTAL_NOT_FOUND');
            }
            $totals = array_values(array_unique($allTotals[1]));
            if (count($totals) !== 1) {
                return $this->fail('AMBIGUOUS_TOTAL');
            }
            $totalRaw = $totals[0];
        } else {
            $totalRaw = $totalMatch[1];
        }

        $cents = $this->brlToCents($totalRaw);
        if ($cents === null) {
            return $this->fail('INVALID_TOTAL');
        }

        return [
            'ok' => true,
            'amount_cents' => $cents,
            'das_number' => $foundNumber,
            'parser_version' => self::VERSION,
            'reason' => null,
        ];
    }

    private function brlToCents(string $raw): ?int
    {
        $normalized = str_replace('.', '', trim($raw));
        $normalized = str_replace(',', '.', $normalized);
        if (! is_numeric($normalized)) {
            return null;
        }
        $cents = (int) round(((float) $normalized) * 100);

        return $cents >= 0 ? $cents : null;
    }

    /**
     * @return array{
     *   ok: bool,
     *   amount_cents: null,
     *   das_number: null,
     *   parser_version: string,
     *   reason: string
     * }
     */
    private function fail(string $reason): array
    {
        return [
            'ok' => false,
            'amount_cents' => null,
            'das_number' => null,
            'parser_version' => self::VERSION,
            'reason' => $reason,
        ];
    }
}
