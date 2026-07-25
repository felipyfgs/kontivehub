<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\Enums\PgdasdDeclarationState;
use App\Models\PgdasdOperation;
use App\Models\TaxDeadlineCalendarVersion;
use App\Models\TaxObligationProjection;
use Carbon\CarbonImmutable;

/**
 * Estado fail-closed da declaração PGDAS-D para o PA esperado.
 */
final class PgdasdDeclarationStateResolver
{
    /**
     * @return array{
     *   state: PgdasdDeclarationState,
     *   calendar_verified: bool,
     *   calendar_version_code: ?string,
     *   due_at: ?CarbonImmutable,
     *   reason: string
     * }
     */
    public function resolve(
        ?PgdasdOperation $declarationForExpectedPa,
        ?CarbonImmutable $lastProductiveConsultedAt,
        ?TaxObligationProjection $projection,
        bool $responseIncomplete = false,
        bool $simulated = false,
    ): array {
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

        if ($simulated || $responseIncomplete || $lastProductiveConsultedAt === null) {
            return $this->pack(PgdasdDeclarationState::Unverified, $calendarVerified, $calendarCode, $dueAt, 'QUERY_NOT_VALID');
        }

        if ($declarationForExpectedPa !== null) {
            return $this->pack(PgdasdDeclarationState::Current, $calendarVerified, $calendarCode, $dueAt, 'EXPECTED_PA_FOUND');
        }

        // Ausência confirmada por consulta produtiva
        if ($dueAt === null) {
            return $this->pack(PgdasdDeclarationState::Unverified, $calendarVerified, $calendarCode, $dueAt, 'DEADLINE_UNAVAILABLE');
        }

        $now = CarbonImmutable::now();
        if ($now->lessThanOrEqualTo($dueAt)) {
            return $this->pack(PgdasdDeclarationState::DueWithinDeadline, $calendarVerified, $calendarCode, $dueAt, 'WITHIN_DEADLINE');
        }

        // Atraso só se consulta produtiva posterior ao vencimento E calendário verificado
        if ($calendarVerified && $lastProductiveConsultedAt->greaterThan($dueAt)) {
            return $this->pack(PgdasdDeclarationState::OverdueNotFound, $calendarVerified, $calendarCode, $dueAt, 'ABSENT_AFTER_VERIFIED_DEADLINE');
        }

        return $this->pack(PgdasdDeclarationState::Unverified, $calendarVerified, $calendarCode, $dueAt, 'CALENDAR_NOT_VERIFIED');
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

        // Flag explícita em coluna metadata.verified
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
     * @return array{
     *   state: PgdasdDeclarationState,
     *   calendar_verified: bool,
     *   calendar_version_code: ?string,
     *   due_at: ?CarbonImmutable,
     *   reason: string
     * }
     */
    private function pack(
        PgdasdDeclarationState $state,
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
