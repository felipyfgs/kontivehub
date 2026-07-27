<?php

namespace Tests\Unit\Domain\Work;

use App\Domain\Work\WorkRoutineRecurrenceSchedule;
use App\Enums\Work\RecurrenceFrequency;
use App\Enums\Work\RecurrencePeriodOffset;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkRoutineRecurrenceConfigTest extends TestCase
{
    public function test_defaults_day_one_and_previous_offset(): void
    {
        $schedule = WorkRoutineRecurrenceSchedule::fromArray([
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Monthly->value,
        ]);

        $this->assertTrue($schedule->enabled);
        $this->assertSame(1, $schedule->generationDay);
        $this->assertSame(RecurrencePeriodOffset::Previous, $schedule->periodOffset);
        $this->assertSame(RecurrenceFrequency::Monthly, $schedule->frequency);
    }

    #[DataProvider('invalidDaysProvider')]
    public function test_rejects_invalid_generation_day(int $day): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkRoutineRecurrenceSchedule::fromArray([
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Monthly->value,
            'generation_day' => $day,
        ]);
    }

    /**
     * @return list<array{int}>
     */
    public static function invalidDaysProvider(): array
    {
        return [[0], [29], [30], [31]];
    }

    public function test_monthly_previous_period_on_generation_day(): void
    {
        $schedule = WorkRoutineRecurrenceSchedule::fromArray([
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Monthly->value,
            'generation_day' => 1,
            'period_offset' => RecurrencePeriodOffset::Previous->value,
        ]);

        $period = $schedule->periodForRunLocalDate(CarbonImmutable::parse('2026-07-01', 'America/Sao_Paulo'));

        $this->assertSame('2026-06', $period->value());
    }

    public function test_quarterly_previous_period_uses_anchor(): void
    {
        $schedule = WorkRoutineRecurrenceSchedule::fromArray([
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Quarterly->value,
            'generation_day' => 1,
            'anchor_month' => 1,
            'period_offset' => RecurrencePeriodOffset::Previous->value,
        ]);

        $period = $schedule->periodForRunLocalDate(CarbonImmutable::parse('2026-04-01', 'America/Sao_Paulo'));

        $this->assertSame('2026-T1', $period->value());
    }

    public function test_yearly_previous_period(): void
    {
        $schedule = WorkRoutineRecurrenceSchedule::fromArray([
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Yearly->value,
            'generation_day' => 1,
            'anchor_month' => 1,
            'period_offset' => RecurrencePeriodOffset::Previous->value,
        ]);

        $period = $schedule->periodForRunLocalDate(CarbonImmutable::parse('2027-01-01', 'America/Sao_Paulo'));

        $this->assertSame('2026', $period->value());
    }

    public function test_upcoming_run_respects_tenant_timezone_near_midnight(): void
    {
        $tenant = new Tenant(['timezone' => 'America/Sao_Paulo']);
        $schedule = WorkRoutineRecurrenceSchedule::fromArray([
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Monthly->value,
            'generation_day' => 1,
        ]);

        // 2026-06-30 23:30 UTC = 2026-06-30 20:30 America/Sao_Paulo → próximo é 2026-07-01 03:00 UTC
        $nowUtc = CarbonImmutable::parse('2026-06-30 23:30:00', 'UTC');
        $next = $schedule->upcomingRunAtUtc($tenant, $nowUtc);

        $this->assertSame('2026-07-01T03:00:00+00:00', $next->toIso8601String());
        $this->assertSame(1, $next->timezone('America/Sao_Paulo')->day);
    }

    public function test_rejects_anchor_month_for_monthly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkRoutineRecurrenceSchedule::fromArray([
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Monthly->value,
            'anchor_month' => 2,
        ]);
    }

    public function test_disabled_schedule_allows_missing_frequency(): void
    {
        $schedule = WorkRoutineRecurrenceSchedule::fromArray([
            'recurrence_enabled' => false,
            'generation_day' => 1,
        ]);

        $this->assertFalse($schedule->enabled);
        $this->assertNull($schedule->frequency);
    }
}
