<?php

namespace App\DTO\Fiscal\SimplesMei;

use InvalidArgumentException;

/** Uma opção anual oficial retornada por CONSULTARANOSCALENDARIOS102. */
final readonly class RegimeCalendarOptionDto
{
    public function __construct(
        public int $calendarYear,
        public string $regimeApuracao,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromOfficialRow(array $row): self
    {
        $year = $row['anoCalendario'] ?? null;
        $regime = strtoupper(trim((string) ($row['regimeApurado'] ?? '')));

        if ((! is_int($year) && ! (is_string($year) && ctype_digit($year)))
            || (int) $year < 2000 || (int) $year > 2100) {
            throw new InvalidArgumentException('Resposta Regime 102 inválida: anoCalendario deve estar entre 2000 e 2100.');
        }
        if (! in_array($regime, ['COMPETENCIA', 'CAIXA'], true)) {
            throw new InvalidArgumentException('Resposta Regime 102 inválida: regimeApurado deve ser COMPETENCIA ou CAIXA.');
        }

        return new self((int) $year, $regime);
    }

    /** @return array{calendar_year:int,regime_apuracao:string} */
    public function toArray(): array
    {
        return [
            'calendar_year' => $this->calendarYear,
            'regime_apuracao' => $this->regimeApuracao,
        ];
    }
}
