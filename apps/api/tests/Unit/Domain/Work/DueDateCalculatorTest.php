<?php

namespace Tests\Unit\Domain\Work;

use App\Domain\Work\CompetenceMonth;
use App\Domain\Work\DueDateCalculator;
use App\Domain\Work\DueRule;
use App\Enums\Work\DueRuleType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DueDateCalculatorTest extends TestCase
{
    #[DataProvider('invalidCivilDates')]
    public function test_rejects_nonexistent_civil_dates_instead_of_normalizing_them(string $date): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Prazo do processo inválido (use Y-m-d).');

        $this->calculateBefore($date);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCivilDates(): iterable
    {
        yield 'dia inexistente' => ['2026-02-31'];
        yield 'mês inexistente' => ['2026-13-01'];
        yield 'dia zero' => ['2026-01-00'];
    }

    public function test_calculates_from_valid_common_and_leap_dates(): void
    {
        self::assertSame('2026-02-28', $this->calculateBefore('2026-03-01'));
        self::assertSame('2028-02-28', $this->calculateBefore('2028-02-29'));
    }

    private function calculateBefore(string $processDueDate): string
    {
        return (new DueDateCalculator)->calculate(
            new DueRule(DueRuleType::DaysBeforeProcessDue, 1),
            CompetenceMonth::fromString('2026-02'),
            'America/Sao_Paulo',
            $processDueDate,
        );
    }
}
