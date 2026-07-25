<?php

namespace App\Services\Fiscal\Dctfweb;

use App\Enums\DctfwebDeclarationState;
use App\Enums\TaxObligationApplicability;
use App\Models\DctfwebDeclaration;
use App\Models\TaxDeadlineCalendarVersion;
use App\Models\TaxObligationProjection;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdBankingCalendar;
use Carbon\CarbonImmutable;

/**
 * Estado fail-closed da declaração DCTFWeb para o PA esperado.
 */
final class DctfwebDeclarationStateResolver
{
    public function __construct(
        private readonly PgdasdBankingCalendar $bankingCalendar = new PgdasdBankingCalendar,
    ) {}

    /**
     * @return array{
     *   state: DctfwebDeclarationState,
     *   calendar_verified: bool,
     *   calendar_version_code: ?string,
     *   due_at: ?CarbonImmutable,
     *   reason: string
     * }
     */
    public function resolve(
        ?DctfwebDeclaration $declarationForExpectedPa,
        ?CarbonImmutable $lastProductiveConsultedAt,
        ?TaxObligationProjection $projection,
        bool $responseIncomplete = false,
        bool $simulated = false,
        bool $noMovement = false,
        bool $documentValid = false,
    ): array {
        $duePack = $this->resolveDue($projection, $declarationForExpectedPa?->period_key);
        $dueAt = $duePack['due_at'];
        $calendarVerified = $duePack['calendar_verified'];
        $calendarCode = $duePack['calendar_version_code'];

        if ($simulated || $responseIncomplete || $lastProductiveConsultedAt === null) {
            return $this->pack(DctfwebDeclarationState::Unverified, $calendarVerified, $calendarCode, $dueAt, 'QUERY_NOT_VALID');
        }

        // Documento produtivo válido confirma declaração.
        if ($documentValid || ($declarationForExpectedPa !== null && $declarationForExpectedPa->receipt_number)) {
            if ($noMovement || ($declarationForExpectedPa?->no_movement === true)) {
                return $this->pack(
                    DctfwebDeclarationState::NoMovementValid,
                    $calendarVerified,
                    $calendarCode,
                    $dueAt,
                    'NO_MOVEMENT_CONFIRMED',
                );
            }

            return $this->pack(DctfwebDeclarationState::Current, $calendarVerified, $calendarCode, $dueAt, 'EXPECTED_PA_FOUND');
        }

        // Sem movimento persistente de PA anterior, sem evidência de retomada.
        if ($declarationForExpectedPa !== null
            && $declarationForExpectedPa->no_movement === true
            && $declarationForExpectedPa->declaration_state === DctfwebDeclarationState::NoMovementValid) {
            return $this->pack(
                DctfwebDeclarationState::NoMovementValid,
                $calendarVerified,
                $calendarCode,
                $dueAt,
                'NO_MOVEMENT_PERSISTED',
            );
        }

        $applicability = $projection?->applicability;
        if ($applicability !== null && $applicability !== TaxObligationApplicability::Applicable) {
            return $this->pack(
                DctfwebDeclarationState::Unverified,
                $calendarVerified,
                $calendarCode,
                $dueAt,
                'OBLIGATION_NOT_APPLICABLE',
            );
        }

        if ($applicability === null) {
            return $this->pack(
                DctfwebDeclarationState::Unverified,
                $calendarVerified,
                $calendarCode,
                $dueAt,
                'OBLIGATION_UNKNOWN',
            );
        }

        if ($dueAt === null) {
            return $this->pack(DctfwebDeclarationState::Unverified, $calendarVerified, $calendarCode, $dueAt, 'DEADLINE_UNAVAILABLE');
        }

        $now = CarbonImmutable::now();
        if ($now->lessThanOrEqualTo($dueAt)) {
            return $this->pack(DctfwebDeclarationState::DueWithinDeadline, $calendarVerified, $calendarCode, $dueAt, 'WITHIN_DEADLINE');
        }

        if ($calendarVerified && $lastProductiveConsultedAt->greaterThan($dueAt)) {
            return $this->pack(
                DctfwebDeclarationState::OverdueNotFound,
                $calendarVerified,
                $calendarCode,
                $dueAt,
                'ABSENT_AFTER_VERIFIED_DEADLINE',
            );
        }

        return $this->pack(DctfwebDeclarationState::Unverified, $calendarVerified, $calendarCode, $dueAt, 'CALENDAR_NOT_VERIFIED');
    }

    /**
     * Calcula prazo = último dia útil do mês seguinte ao PA.
     *
     * @return array{due_at:?CarbonImmutable,calendar_verified:bool,calendar_version_code:?string}
     */
    public function resolveDue(?TaxObligationProjection $projection, ?string $periodKey = null): array
    {
        $dueAt = $projection?->due_at instanceof CarbonImmutable
            ? $projection->due_at
            : ($projection?->due_at !== null ? CarbonImmutable::parse($projection->due_at) : null);

        $calendar = null;
        if ($projection?->calendar_version_id !== null) {
            $calendar = TaxDeadlineCalendarVersion::query()->find($projection->calendar_version_id);
        }

        $calendarVerified = $this->isCalendarVerified($calendar)
            && $this->isDeadlineAdjustmentVerified($projection);
        $calendarCode = $calendar?->code;

        if ($dueAt === null && is_string($periodKey) && $periodKey !== '') {
            try {
                $pa = DctfwebPeriod::parse($periodKey);
                $raw = DctfwebPeriod::rawDueDate($pa);
                $holidays = $this->nonBusinessDates($calendar);
                $adjusted = $this->bankingCalendar->applyAdjustment(
                    $raw,
                    PgdasdBankingCalendar::ADJUSTMENT,
                    $holidays,
                    $calendarVerified,
                );
                $dueAt = $adjusted['date'];
                $calendarVerified = $calendarVerified && $adjusted['verified'];
            } catch (\Throwable) {
                $dueAt = null;
                $calendarVerified = false;
            }
        }

        return [
            'due_at' => $dueAt,
            'calendar_verified' => $calendarVerified,
            'calendar_version_code' => $calendarCode,
        ];
    }

    private function isCalendarVerified(?TaxDeadlineCalendarVersion $calendar): bool
    {
        if ($calendar === null) {
            return false;
        }

        $meta = is_array($calendar->metadata) ? $calendar->metadata : [];
        $verification = strtoupper((string) ($meta['verification'] ?? $meta['status'] ?? ''));
        $hasOfficialSource = is_string($calendar->source_ref) && trim($calendar->source_ref) !== ''
            || ($meta['official_source'] ?? false) === true;
        if ($verification === 'VERIFIED' && $hasOfficialSource) {
            return true;
        }

        if (($meta['verified'] ?? false) === true && $hasOfficialSource) {
            return true;
        }

        return false;
    }

    private function isDeadlineAdjustmentVerified(?TaxObligationProjection $projection): bool
    {
        $snapshot = $projection?->due_rule_snapshot;
        if (! is_array($snapshot) || $snapshot === []) {
            return true;
        }
        $adjustment = strtoupper((string) ($snapshot['business_day_adjustment'] ?? 'NONE'));
        if ($adjustment === '' || $adjustment === 'NONE') {
            return true;
        }

        return ($snapshot['calendar_verified'] ?? false) === true
            && ($snapshot['business_day_adjustment_reason'] ?? null) !== 'UNKNOWN_ADJUSTMENT';
    }

    /**
     * @return list<string>
     */
    private function nonBusinessDates(?TaxDeadlineCalendarVersion $calendar): array
    {
        if ($calendar === null) {
            return [];
        }
        $meta = is_array($calendar->metadata) ? $calendar->metadata : [];
        $dates = $meta['non_business_dates'] ?? $meta['holidays'] ?? [];
        if (! is_array($dates)) {
            return [];
        }

        return array_values(array_filter(
            $dates,
            static fn (mixed $item): bool => is_string($item) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $item) === 1,
        ));
    }

    /**
     * @return array{
     *   state: DctfwebDeclarationState,
     *   calendar_verified: bool,
     *   calendar_version_code: ?string,
     *   due_at: ?CarbonImmutable,
     *   reason: string
     * }
     */
    private function pack(
        DctfwebDeclarationState $state,
        bool $calendarVerified,
        ?string $calendarCode,
        ?CarbonImmutable $dueAt,
        string $reason,
    ): array {
        return [
            'state' => $state,
            'calendar_verified' => $calendarVerified,
            'calendar_version_code' => $calendarCode,
            'due_at' => $dueAt,
            'reason' => $reason,
        ];
    }
}
