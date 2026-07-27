<?php

namespace App\Domain\Work;

use App\Enums\Work\ReferencePeriodType;
use InvalidArgumentException;
use Stringable;

/**
 * Período de referência tipado: mensal (YYYY-MM), trimestral (YYYY-Tn) ou anual (YYYY).
 *
 * A chave canônica ({@see value()}) permanece compatível com a coluna `competence`
 * durante a transição.
 */
final readonly class ReferencePeriod implements Stringable
{
    public const MIN_YEAR = 2000;

    public const MAX_YEAR = 2100;

    private function __construct(
        public ReferencePeriodType $type,
        public int $year,
        public ?int $month,
        public ?int $quarter,
    ) {}

    public static function fromString(string $raw): self
    {
        $value = trim($raw);

        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m) === 1) {
            return self::monthly((int) $m[1], (int) $m[2]);
        }

        if (preg_match('/^(\d{4})-T([1-4])$/', $value, $m) === 1) {
            return self::quarterly((int) $m[1], (int) $m[2]);
        }

        if (preg_match('/^(\d{4})$/', $value, $m) === 1) {
            return self::annual((int) $m[1]);
        }

        throw new InvalidArgumentException(
            'Período de referência inválido (use YYYY-MM, YYYY-Tn ou YYYY).',
        );
    }

    public static function monthly(int $year, int $month): self
    {
        self::assertYear($year);
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Mês de período inválido.');
        }

        return new self(ReferencePeriodType::Monthly, $year, $month, null);
    }

    public static function quarterly(int $year, int $quarter): self
    {
        self::assertYear($year);
        if ($quarter < 1 || $quarter > 4) {
            throw new InvalidArgumentException('Trimestre de período inválido (use 1–4).');
        }

        return new self(ReferencePeriodType::Quarterly, $year, null, $quarter);
    }

    public static function annual(int $year): self
    {
        self::assertYear($year);

        return new self(ReferencePeriodType::Annual, $year, null, null);
    }

    public static function fromCompetenceMonth(CompetenceMonth $competence): self
    {
        return self::monthly($competence->year, $competence->month);
    }

    public function value(): string
    {
        return match ($this->type) {
            ReferencePeriodType::Monthly => sprintf('%04d-%02d', $this->year, $this->month),
            ReferencePeriodType::Quarterly => sprintf('%04d-T%d', $this->year, $this->quarter),
            ReferencePeriodType::Annual => sprintf('%04d', $this->year),
        };
    }

    /** Primeiro dia civil do período (Y-m-d). */
    public function startDate(): string
    {
        return match ($this->type) {
            ReferencePeriodType::Monthly => sprintf('%04d-%02d-01', $this->year, $this->month),
            ReferencePeriodType::Quarterly => sprintf('%04d-%02d-01', $this->year, (($this->quarter - 1) * 3) + 1),
            ReferencePeriodType::Annual => sprintf('%04d-01-01', $this->year),
        };
    }

    /** Último dia civil do período (Y-m-d). */
    public function endDate(): string
    {
        $start = new \DateTimeImmutable($this->startDate());

        $end = match ($this->type) {
            ReferencePeriodType::Monthly => $start->modify('last day of this month'),
            ReferencePeriodType::Quarterly => $start->modify('+2 months')->modify('last day of this month'),
            ReferencePeriodType::Annual => $start->setDate($this->year, 12, 31),
        };

        return $end->format('Y-m-d');
    }

    /**
     * Âncora mensal para regras de prazo FixedDay e DaysAfterStart.
     * Usa o mês de início do período.
     */
    public function toCompetenceMonth(): CompetenceMonth
    {
        $start = $this->startDate();

        return CompetenceMonth::fromYearMonth(
            (int) substr($start, 0, 4),
            (int) substr($start, 5, 2),
        );
    }

    public function next(): self
    {
        return match ($this->type) {
            ReferencePeriodType::Monthly => $this->month === 12
                ? self::monthly($this->year + 1, 1)
                : self::monthly($this->year, $this->month + 1),
            ReferencePeriodType::Quarterly => $this->quarter === 4
                ? self::quarterly($this->year + 1, 1)
                : self::quarterly($this->year, $this->quarter + 1),
            ReferencePeriodType::Annual => self::annual($this->year + 1),
        };
    }

    public function previous(): self
    {
        return match ($this->type) {
            ReferencePeriodType::Monthly => $this->month === 1
                ? self::monthly($this->year - 1, 12)
                : self::monthly($this->year, $this->month - 1),
            ReferencePeriodType::Quarterly => $this->quarter === 1
                ? self::quarterly($this->year - 1, 4)
                : self::quarterly($this->year, $this->quarter - 1),
            ReferencePeriodType::Annual => self::annual($this->year - 1),
        };
    }

    /**
     * @return array{type: string, key: string, start: string, end: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'key' => $this->value(),
            'start' => $this->startDate(),
            'end' => $this->endDate(),
        ];
    }

    public function __toString(): string
    {
        return $this->value();
    }

    private static function assertYear(int $year): void
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new InvalidArgumentException(sprintf(
                'Ano de período fora do intervalo permitido (%d–%d).',
                self::MIN_YEAR,
                self::MAX_YEAR,
            ));
        }
    }
}
