<?php

namespace App\Services\Fiscal\Declarations;

use App\Enums\TaxPeriodGranularity;
use App\Models\TaxDeadlineCalendarVersion;
use App\Models\TaxDeadlineRule;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdBankingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Calendário versionado e recalculo de competências abertas em prorrogação (11.4).
 */
final class TaxDeadlineCalendarService
{
    public function __construct(
        private readonly TaxObligationCatalogService $catalog,
        private readonly PgdasdBankingCalendar $bankingCalendar,
    ) {}

    /**
     * Calcula vencimento timezone-aware a partir da regra do calendário.
     *
     * @return array{
     *   due_at: CarbonImmutable|null,
     *   calendar_version_id: int|null,
     *   snapshot: array<string, mixed>
     * }
     */
    public function calculateDue(
        TaxObligationDefinition $obligation,
        string $periodKey,
        int $periodYear,
        ?int $periodMonth = null,
        ?TaxDeadlineCalendarVersion $calendar = null,
    ): array {
        $calendar ??= $this->catalog->currentCalendar();
        if ($calendar === null) {
            return [
                'due_at' => null,
                'calendar_version_id' => null,
                'snapshot' => [
                    'error' => 'NO_CURRENT_CALENDAR',
                    'period_key' => $periodKey,
                ],
            ];
        }

        $rule = $this->resolveRule($calendar, $obligation);
        if ($rule === null) {
            return [
                'due_at' => null,
                'calendar_version_id' => $calendar->id,
                'snapshot' => [
                    'error' => 'NO_DEADLINE_RULE',
                    'calendar_version_id' => $calendar->id,
                    'calendar_code' => $calendar->code,
                    'calendar_version' => $calendar->version,
                    'obligation_code' => $obligation->code,
                    'period_key' => $periodKey,
                ],
            ];
        }

        $tz = $rule->timezone ?: $calendar->timezone ?: $obligation->default_timezone ?: 'America/Sao_Paulo';
        $rawDueAt = $this->computeDueAt($rule, $periodYear, $periodMonth, $tz);
        $calendarMetadata = is_array($calendar->metadata) ? $calendar->metadata : [];
        $ruleMetadata = is_array($rule->metadata) ? $rule->metadata : [];
        $nonBusinessDates = $ruleMetadata['non_business_dates']
            ?? $calendarMetadata['non_business_dates']
            ?? [];
        if (! is_array($nonBusinessDates)) {
            $nonBusinessDates = [];
        }
        $verification = strtoupper((string) ($calendarMetadata['verification'] ?? $calendarMetadata['status'] ?? ''));
        $calendarVerified = $verification === 'VERIFIED' || ($calendarMetadata['verified'] ?? false) === true;
        $adjustment = $rawDueAt === null ? null : $this->bankingCalendar->applyAdjustment(
            $rawDueAt,
            (string) $rule->business_day_adjustment,
            array_values($nonBusinessDates),
            $calendarVerified,
        );
        $dueAt = $adjustment['date'] ?? null;

        return [
            'due_at' => $dueAt,
            'calendar_version_id' => $calendar->id,
            'snapshot' => [
                'calendar_code' => $calendar->code,
                'calendar_version' => $calendar->version,
                'calendar_version_id' => $calendar->id,
                'rule_id' => $rule->id,
                'timezone' => $tz,
                'period_key' => $periodKey,
                'period_year' => $periodYear,
                'period_month' => $periodMonth,
                'due_day' => $rule->due_day,
                'due_month_offset' => $rule->due_month_offset,
                'fixed_due_month' => $rule->fixed_due_month,
                'fixed_due_day' => $rule->fixed_due_day,
                'business_day_adjustment' => $rule->business_day_adjustment,
                'business_day_adjustment_reason' => $adjustment['reason'] ?? 'DUE_DATE_UNAVAILABLE',
                'calendar_verified' => $adjustment['verified'] ?? false,
                'raw_due_at' => $rawDueAt?->toIso8601String(),
                'computed_at' => CarbonImmutable::now($tz)->toIso8601String(),
                'due_at' => $dueAt?->toIso8601String(),
            ],
        ];
    }

    /**
     * Publica nova versão de calendário (ex.: prorrogação oficial) e recalcula
     * apenas projeções abertas do mesmo code.
     *
     * @param  list<array{
     *   obligation_code: string,
     *   period_granularity?: string,
     *   due_day?: int|null,
     *   due_month_offset?: int,
     *   fixed_due_month?: int|null,
     *   fixed_due_day?: int|null,
     *   business_day_adjustment?: string,
     *   timezone?: string|null,
     *   metadata?: array<string, mixed>|null
     * }>  $rules
     * @return array{calendar: TaxDeadlineCalendarVersion, recalculated: int}
     */
    public function publishCalendarVersion(
        string $code,
        string $label,
        array $rules,
        ?string $sourceRef = null,
        ?string $notes = null,
        ?string $timezone = 'America/Sao_Paulo',
        ?CarbonImmutable $effectiveFrom = null,
        bool $recalculateOpen = true,
    ): array {
        return DB::transaction(function () use (
            $code,
            $label,
            $rules,
            $sourceRef,
            $notes,
            $timezone,
            $effectiveFrom,
            $recalculateOpen,
        ) {
            $prev = TaxDeadlineCalendarVersion::query()
                ->where('code', $code)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            $nextVersion = ($prev?->version ?? 0) + 1;
            $from = $effectiveFrom ?? CarbonImmutable::now($timezone ?? 'America/Sao_Paulo');

            // Encerra vigência da corrente
            TaxDeadlineCalendarVersion::query()
                ->where('code', $code)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'effective_to' => $from,
                    'updated_at' => now(),
                ]);

            $calendar = TaxDeadlineCalendarVersion::query()->create([
                'code' => $code,
                'version' => $nextVersion,
                'label' => $label,
                'timezone' => $timezone ?? 'America/Sao_Paulo',
                'effective_from' => $from,
                'effective_to' => null,
                'is_current' => true,
                'source_ref' => $sourceRef,
                'notes' => $notes,
                'metadata' => [
                    'previous_version_id' => $prev?->id,
                    'reason' => 'OFFICIAL_EXTENSION',
                ],
            ]);

            foreach ($rules as $ruleRow) {
                $oblCode = strtoupper((string) ($ruleRow['obligation_code'] ?? ''));
                $obligation = $this->catalog->findByCode($oblCode);
                if ($obligation === null) {
                    throw new InvalidArgumentException("Obrigação desconhecida no calendário: {$oblCode}");
                }

                TaxDeadlineRule::query()->create([
                    'calendar_version_id' => $calendar->id,
                    'obligation_definition_id' => $obligation->id,
                    'period_granularity' => $ruleRow['period_granularity']
                        ?? $obligation->period_granularity?->value
                        ?? TaxPeriodGranularity::Monthly->value,
                    'due_day' => $ruleRow['due_day'] ?? null,
                    'due_month_offset' => $ruleRow['due_month_offset'] ?? 1,
                    'fixed_due_month' => $ruleRow['fixed_due_month'] ?? null,
                    'fixed_due_day' => $ruleRow['fixed_due_day'] ?? null,
                    'business_day_adjustment' => $ruleRow['business_day_adjustment'] ?? 'NONE',
                    'timezone' => $ruleRow['timezone'] ?? $timezone,
                    'metadata' => $ruleRow['metadata'] ?? null,
                ]);
            }

            $recalculated = 0;
            if ($recalculateOpen) {
                $recalculated = $this->recalculateOpenProjections($calendar);
            }

            return ['calendar' => $calendar->fresh('rules'), 'recalculated' => $recalculated];
        });
    }

    /**
     * Recalcula due_at de projeções abertas com o calendário informado (corrente).
     * Histórico do cálculo anterior é preservado em due_history.
     */
    public function recalculateOpenProjections(?TaxDeadlineCalendarVersion $calendar = null): int
    {
        $calendar ??= $this->catalog->currentCalendar();
        if ($calendar === null) {
            throw new RuntimeException('Nenhum calendário corrente disponível.');
        }

        $count = 0;
        TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->with('obligation')
            ->where('is_open', true)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($calendar, &$count) {
                foreach ($rows as $projection) {
                    /** @var TaxObligationProjection $projection */
                    $obligation = $projection->obligation;
                    if ($obligation === null) {
                        continue;
                    }

                    $calc = $this->calculateDue(
                        $obligation,
                        $projection->period_key,
                        $projection->period_year,
                        $projection->period_month,
                        $calendar,
                    );

                    $history = $projection->due_history ?? [];
                    if ($projection->due_rule_snapshot !== null || $projection->due_at !== null) {
                        $history[] = [
                            'superseded_at' => CarbonImmutable::now()->toIso8601String(),
                            'reason' => 'CALENDAR_EXTENSION',
                            'previous_due_at' => $projection->due_at?->toIso8601String(),
                            'previous_calendar_version_id' => $projection->calendar_version_id,
                            'previous_snapshot' => $projection->due_rule_snapshot,
                        ];
                    }

                    $projection->forceFill([
                        'due_at' => $calc['due_at'],
                        'calendar_version_id' => $calc['calendar_version_id'],
                        'due_rule_snapshot' => $calc['snapshot'],
                        'due_history' => $history,
                    ])->save();
                    $count++;
                }
            });

        return $count;
    }

    private function resolveRule(
        TaxDeadlineCalendarVersion $calendar,
        TaxObligationDefinition $obligation,
    ): ?TaxDeadlineRule {
        return TaxDeadlineRule::query()
            ->where('calendar_version_id', $calendar->id)
            ->where('obligation_definition_id', $obligation->id)
            ->first();
    }

    private function computeDueAt(
        TaxDeadlineRule $rule,
        int $periodYear,
        ?int $periodMonth,
        string $tz,
    ): ?CarbonImmutable {
        $granularity = $rule->period_granularity ?? TaxPeriodGranularity::Monthly;

        if ($granularity === TaxPeriodGranularity::Annual
            || ($rule->fixed_due_month !== null && $rule->fixed_due_day !== null)
        ) {
            // Anual: vencimento no ano seguinte à competência (ex.: DEFIS 2024 → 31/03/2025)
            $year = $periodYear + 1;
            $month = (int) ($rule->fixed_due_month ?? 3);
            $day = (int) ($rule->fixed_due_day ?? 31);
            $day = min($day, CarbonImmutable::create($year, $month, 1, 0, 0, 0, $tz)->endOfMonth()->day);

            return CarbonImmutable::create($year, $month, $day, 23, 59, 59, $tz);
        }

        if ($periodMonth === null || $periodMonth < 1 || $periodMonth > 12) {
            return null;
        }

        $base = CarbonImmutable::create($periodYear, $periodMonth, 1, 0, 0, 0, $tz);
        $target = $base->addMonths((int) $rule->due_month_offset);
        $dueDay = (int) ($rule->due_day ?? 20);
        $dueDay = min($dueDay, $target->endOfMonth()->day);

        return CarbonImmutable::create(
            $target->year,
            $target->month,
            $dueDay,
            23,
            59,
            59,
            $tz,
        );
    }
}
