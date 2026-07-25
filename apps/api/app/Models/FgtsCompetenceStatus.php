<?php

namespace App\Models;

use App\Enums\FgtsIndependentState;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estados independentes FGTS por competência/estabelecimento.
 * Guia e pagamento do portal permanecem UNSUPPORTED sem API pública.
 */
#[Fillable([
    'office_id',
    'client_id',
    'establishment_id',
    'fiscal_competence_id',
    'run_id',
    'snapshot_id',
    'competence_period_key',
    'closure_status',
    'totalization_status',
    'guide_status',
    'payment_status',
    'coverage',
    'situation',
    'closure_observed_at',
    'totalizer_observed_at',
    'totalizer_due_by',
    'last_synced_at',
    'limitations',
    'metadata',
    'is_quarantined',
    'quarantine_reason',
    'quarantined_at',
])]
class FgtsCompetenceStatus extends Model
{
    use BelongsToOffice;

    protected $table = 'fgts_competence_statuses';

    protected function casts(): array
    {
        return [
            'closure_status' => FgtsIndependentState::class,
            'totalization_status' => FgtsIndependentState::class,
            'guide_status' => FgtsIndependentState::class,
            'payment_status' => FgtsIndependentState::class,
            'coverage' => FiscalCoverage::class,
            'situation' => FiscalSituation::class,
            'closure_observed_at' => 'immutable_datetime',
            'totalizer_observed_at' => 'immutable_datetime',
            'totalizer_due_by' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'limitations' => 'array',
            'metadata' => 'array',
            'is_quarantined' => 'boolean',
            'quarantined_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeOperationallyEligible(Builder $query): void
    {
        $query->where('is_quarantined', false);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function fiscalCompetence(): BelongsTo
    {
        return $this->belongsTo(FiscalCompetence::class, 'fiscal_competence_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'run_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(FiscalSnapshot::class, 'snapshot_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'establishment_id' => $this->establishment_id,
            'competence_period_key' => $this->competence_period_key,
            'closure_status' => $this->closure_status?->value,
            'closure_status_label' => $this->closure_status?->label(),
            'totalization_status' => $this->totalization_status?->value,
            'totalization_status_label' => $this->totalization_status?->label(),
            'guide_status' => $this->guide_status?->value,
            'guide_status_label' => $this->guide_status?->label(),
            'payment_status' => $this->payment_status?->value,
            'payment_status_label' => $this->payment_status?->label(),
            'coverage' => $this->coverage?->value,
            'situation' => $this->situation?->value,
            'closure_observed_at' => $this->closure_observed_at?->toIso8601String(),
            'totalizer_observed_at' => $this->totalizer_observed_at?->toIso8601String(),
            'totalizer_due_by' => $this->totalizer_due_by?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'limitations' => $this->limitations ?? [],
            'partial_coverage' => true,
            'declares_fgts_digital_debt' => false,
            'run_id' => $this->run_id,
            'snapshot_id' => $this->snapshot_id,
            'is_quarantined' => $this->is_quarantined,
            'quarantine_reason' => $this->quarantine_reason,
        ];
    }
}
