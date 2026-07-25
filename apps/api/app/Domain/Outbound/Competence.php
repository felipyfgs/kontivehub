<?php

namespace App\Domain\Outbound;

use InvalidArgumentException;
use Stringable;

/**
 * Competência fiscal YYYY-MM (mês de autorização).
 */
final readonly class Competence implements Stringable
{
    private function __construct(
        public int $year,
        public int $month,
    ) {}

    public static function fromString(string $raw): self
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', trim($raw), $m)) {
            throw new InvalidArgumentException('Competência inválida (use YYYY-MM).');
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Mês de competência inválido.');
        }

        return new self($year, $month);
    }

    public static function fromYearMonth(int $year, int $month): self
    {
        return self::fromString(sprintf('%04d-%02d', $year, $month));
    }

    /**
     * Extrai ano/mês da chave de acesso NF-e/NFC-e (posições 3–6 AAMM após UF).
     * Chave 44 chars: UF(2) + AAMM(4) + ...
     */
    public static function tryFromAccessKey(string $accessKey): ?self
    {
        if (preg_match('/^[0-9A-Z]{44}$/D', $accessKey) !== 1) {
            return null;
        }
        $aamm = substr($accessKey, 2, 4);
        if (! ctype_digit($aamm)) {
            return null;
        }
        $yy = (int) substr($aamm, 0, 2);
        $mm = (int) substr($aamm, 2, 2);
        if ($mm < 1 || $mm > 12) {
            return null;
        }
        // Heurística: 00–79 → 2000–2079; 80–99 → 1980–1999 (pouco relevante para NF-e)
        $year = $yy >= 80 ? 1900 + $yy : 2000 + $yy;

        return new self($year, $mm);
    }

    public function value(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function nextMonth(): self
    {
        if ($this->month === 12) {
            return new self($this->year + 1, 1);
        }

        return new self($this->year, $this->month + 1);
    }

    public function __toString(): string
    {
        return $this->value();
    }
}
