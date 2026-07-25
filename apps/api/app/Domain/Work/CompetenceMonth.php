<?php

namespace App\Domain\Work;

use App\Domain\Outbound\Competence;
use InvalidArgumentException;
use Stringable;

/**
 * Competência operacional canônica YYYY-MM.
 *
 * Reutiliza a semântica de {@see Competence},
 * com limites explícitos para o módulo de processos.
 */
final readonly class CompetenceMonth implements Stringable
{
    public const MIN_YEAR = 2000;

    public const MAX_YEAR = 2100;

    private function __construct(
        public int $year,
        public int $month,
    ) {}

    public static function fromString(string $raw): self
    {
        $value = trim($raw);
        if (! preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) {
            throw new InvalidArgumentException('Competência inválida (use YYYY-MM).');
        }

        $year = (int) $m[1];
        $month = (int) $m[2];

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Mês de competência inválido.');
        }

        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new InvalidArgumentException(sprintf(
                'Ano de competência fora do intervalo permitido (%d–%d).',
                self::MIN_YEAR,
                self::MAX_YEAR,
            ));
        }

        return new self($year, $month);
    }

    public static function fromYearMonth(int $year, int $month): self
    {
        return self::fromString(sprintf('%04d-%02d', $year, $month));
    }

    public function value(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    /** Primeiro dia civil da competência (Y-m-d). */
    public function startDate(): string
    {
        return sprintf('%04d-%02d-01', $this->year, $this->month);
    }

    /** Último dia civil da competência (Y-m-d). */
    public function endDate(): string
    {
        $days = (int) (new \DateTimeImmutable($this->startDate()))->format('t');

        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $days);
    }

    public function __toString(): string
    {
        return $this->value();
    }
}
