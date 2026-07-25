<?php

namespace App\Models;

use App\Enums\PgmeiDebtState;
use App\Enums\PgmeiFreshnessState;
use App\Models\Concerns\BelongsToOffice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'calendar_year',
    'debt_state',
    'items_count',
    'total_cents',
    'last_valid_query_at',
    'last_valid_observation_id',
    'last_valid_run_id',
    'last_valid_snapshot_id',
    'metadata',
])]
class PgmeiDebtProjection extends Model
{
    use BelongsToOffice;

    public const FRESHNESS_DAYS = 7;

    protected function casts(): array
    {
        return [
            'calendar_year' => 'integer',
            'debt_state' => PgmeiDebtState::class,
            'items_count' => 'integer',
            'total_cents' => 'integer',
            'last_valid_query_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lastValidObservation(): BelongsTo
    {
        return $this->belongsTo(PgmeiDebtObservation::class, 'last_valid_observation_id');
    }

    public function lastValidRun(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'last_valid_run_id');
    }

    public function lastValidSnapshot(): BelongsTo
    {
        return $this->belongsTo(FiscalSnapshot::class, 'last_valid_snapshot_id');
    }

    public function freshnessState(?CarbonImmutable $now = null): PgmeiFreshnessState
    {
        $at = $this->last_valid_query_at;
        if ($at === null) {
            return PgmeiFreshnessState::Outdated;
        }

        $now ??= CarbonImmutable::now();
        $threshold = $now->subDays(self::FRESHNESS_DAYS);

        return $at->greaterThanOrEqualTo($threshold)
            ? PgmeiFreshnessState::Current
            : PgmeiFreshnessState::Outdated;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPortfolioArray(?CarbonImmutable $now = null): array
    {
        $state = $this->debt_state instanceof PgmeiDebtState
            ? $this->debt_state
            : PgmeiDebtState::tryFrom((string) $this->debt_state) ?? PgmeiDebtState::Unverified;

        $year = (int) $this->calendar_year;
        $count = (int) $this->items_count;

        return [
            'year' => $year,
            'calendar_year' => $year,
            'debt_state' => $state->value,
            'freshness_state' => $this->freshnessState($now)->value,
            'debt_count' => $count,
            'items_count' => $count,
            'total_cents' => (int) $this->total_cents,
            'last_valid_query_at' => $this->last_valid_query_at?->toIso8601String(),
        ];
    }
}
