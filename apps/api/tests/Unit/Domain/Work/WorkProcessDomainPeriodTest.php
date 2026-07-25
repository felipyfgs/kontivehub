<?php

namespace Tests\Unit\Domain\Work;

use App\Domain\Work\ProcessStateCalculator;
use App\Domain\Work\ReferencePeriod;
use App\Domain\Work\WorkRiskCalculator;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\ReferencePeriodType;
use App\Enums\Work\TaskStatus;
use App\Enums\Work\WorkRisk;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkProcessDomainPeriodTest extends TestCase
{
    #[DataProvider('validPeriods')]
    public function test_parses_valid_periods(string $raw, ReferencePeriodType $type, string $start, string $end): void
    {
        $period = ReferencePeriod::fromString($raw);

        $this->assertSame($type, $period->type);
        $this->assertSame($raw, $period->value());
        $this->assertSame($start, $period->startDate());
        $this->assertSame($end, $period->endDate());
    }

    /**
     * @return array<string, array{0: string, 1: ReferencePeriodType, 2: string, 3: string}>
     */
    public static function validPeriods(): array
    {
        return [
            'mensal' => ['2026-07', ReferencePeriodType::Monthly, '2026-07-01', '2026-07-31'],
            'trimestral' => ['2026-T3', ReferencePeriodType::Quarterly, '2026-07-01', '2026-09-30'],
            'anual' => ['2026', ReferencePeriodType::Annual, '2026-01-01', '2026-12-31'],
            't1' => ['2026-T1', ReferencePeriodType::Quarterly, '2026-01-01', '2026-03-31'],
            'fevereiro' => ['2026-02', ReferencePeriodType::Monthly, '2026-02-01', '2026-02-28'],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_rejects_invalid_periods(string $raw): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReferencePeriod::fromString($raw);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPeriods(): array
    {
        return [
            'mes-13' => ['2026-13'],
            't5' => ['2026-T5'],
            'texto' => ['competencia'],
            'parcial' => ['2026-7'],
            'trimestre-zero' => ['2026-T0'],
        ];
    }

    public function test_navigates_next_and_previous(): void
    {
        $this->assertSame('2026-08', ReferencePeriod::fromString('2026-07')->next()->value());
        $this->assertSame('2026-06', ReferencePeriod::fromString('2026-07')->previous()->value());
        $this->assertSame('2027-01', ReferencePeriod::fromString('2026-12')->next()->value());
        $this->assertSame('2026-T4', ReferencePeriod::fromString('2026-T3')->next()->value());
        $this->assertSame('2027-T1', ReferencePeriod::fromString('2026-T4')->next()->value());
        $this->assertSame('2027', ReferencePeriod::fromString('2026')->next()->value());
        $this->assertSame('2025', ReferencePeriod::fromString('2026')->previous()->value());
    }

    public function test_to_array_exposes_structured_payload(): void
    {
        $this->assertSame([
            'type' => 'QUARTERLY',
            'key' => '2026-T2',
            'start' => '2026-04-01',
            'end' => '2026-06-30',
        ], ReferencePeriod::fromString('2026-T2')->toArray());
    }

    public function test_process_state_derivation_rules(): void
    {
        $calc = new ProcessStateCalculator;

        $this->assertSame(ProcessStatus::Impedido, $calc->derive([
            ['status' => TaskStatus::EmProgresso],
            ['status' => TaskStatus::Impedida],
        ]));

        $this->assertSame(ProcessStatus::Concluido, $calc->derive([
            ['status' => TaskStatus::Concluida],
            ['status' => TaskStatus::Dispensada],
        ]));

        $this->assertSame(ProcessStatus::AFazer, $calc->derive([
            ['status' => TaskStatus::AFazer],
            ['status' => TaskStatus::AFazer],
        ]));

        $this->assertSame(ProcessStatus::EmProgresso, $calc->derive([
            ['status' => TaskStatus::AFazer],
            ['status' => TaskStatus::EmProgresso],
        ]));

        // Impedimento prevalece mesmo com outras terminais
        $this->assertSame(ProcessStatus::Impedido, $calc->derive([
            ['status' => TaskStatus::Concluida],
            ['status' => TaskStatus::Impedida],
        ]));

        // Arquivamento não é mais valor de progresso
        $this->assertSame(ProcessStatus::AFazer, $calc->derive(
            [['status' => TaskStatus::AFazer]],
            ProcessStatus::AFazer,
        ));
    }

    public function test_process_without_tasks_invariant_is_a_fazer_for_derivation(): void
    {
        $this->assertSame(ProcessStatus::AFazer, (new ProcessStateCalculator)->derive([]));
    }

    public function test_effective_due_date_precedence_and_risks(): void
    {
        $risks = new WorkRiskCalculator;

        $this->assertSame(
            '2026-07-10',
            $risks->effectiveDueDate('2026-07-10', '2026-07-05', '2026-07-20'),
        );
        $this->assertSame(
            '2026-07-05',
            $risks->effectiveDueDate(null, '2026-07-05', '2026-07-20'),
        );
        $this->assertSame(
            '2026-07-20',
            $risks->effectiveDueDate(null, null, '2026-07-20'),
        );
        $this->assertNull($risks->effectiveDueDate(null, null, null));

        // ATRASADA pelo efetivo (meta), EM_MULTA pelo legal
        $list = $risks->forTask(
            TaskStatus::EmProgresso,
            null,
            '2026-07-01',
            '2026-07-10',
            true,
            1,
            '2026-07-05',
        );
        $values = array_map(fn (WorkRisk $r) => $r->value, $list);
        $this->assertContains(WorkRisk::Atrasada->value, $values);
        $this->assertNotContains(WorkRisk::EmMulta->value, $values);

        $listFine = $risks->forTask(
            TaskStatus::EmProgresso,
            null,
            '2026-07-01',
            '2026-07-01',
            true,
            1,
            '2026-07-05',
        );
        $fineValues = array_map(fn (WorkRisk $r) => $r->value, $listFine);
        $this->assertContains(WorkRisk::Atrasada->value, $fineValues);
        $this->assertContains(WorkRisk::EmMulta->value, $fineValues);
    }
}
