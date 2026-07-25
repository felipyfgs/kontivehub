<?php

namespace App\Models;

use App\Enums\DctfwebDeclarationState;
use App\Enums\FiscalSituation;
use App\Enums\PgdasdDeclarationState;
use App\Enums\TaxObligationApplicability;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Projeção de obrigação por tenant/contribuinte/competência.
 */
#[Fillable([
    'office_id',
    'client_id',
    'obligation_definition_id',
    'obligation_version_id',
    'calendar_version_id',
    'competence_id',
    'period_key',
    'period_year',
    'period_month',
    'applicability',
    'situation',
    'delivery_status',
    'due_at',
    'due_rule_snapshot',
    'due_history',
    'applicability_basis',
    'is_open',
    'closed_at',
    'conclusive_evidence_id',
    'evidence_artifact_id',
    'last_valid_query_at',
    'last_valid_run_id',
    'last_valid_snapshot_id',
    'metadata',
    'pgdasd_declaration_state',
    'pgdasd_last_productive_consulted_at',
    'pgdasd_last_declaration_operation_id',
    'pgdasd_latest_rbt12_projection_id',
    'pgdasd_calendar_version_code',
    'pgdasd_calendar_verified',
    'dctfweb_declaration_state',
    'dctfweb_last_productive_consulted_at',
    'dctfweb_last_declaration_id',
    'dctfweb_calendar_version_code',
    'dctfweb_calendar_verified',
    'dctfweb_category',
])]
class TaxObligationProjection extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'applicability' => TaxObligationApplicability::class,
            'situation' => FiscalSituation::class,
            'delivery_status' => FiscalSituation::class,
            'period_year' => 'integer',
            'period_month' => 'integer',
            'due_at' => 'immutable_datetime',
            'due_rule_snapshot' => 'array',
            'due_history' => 'array',
            'is_open' => 'boolean',
            'closed_at' => 'immutable_datetime',
            'last_valid_query_at' => 'immutable_datetime',
            'metadata' => 'array',
            'pgdasd_declaration_state' => PgdasdDeclarationState::class,
            'pgdasd_last_productive_consulted_at' => 'immutable_datetime',
            'pgdasd_calendar_verified' => 'boolean',
            'dctfweb_declaration_state' => DctfwebDeclarationState::class,
            'dctfweb_last_productive_consulted_at' => 'immutable_datetime',
            'dctfweb_calendar_verified' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(TaxObligationDefinition::class, 'obligation_definition_id');
    }

    public function obligationVersion(): BelongsTo
    {
        return $this->belongsTo(TaxObligationVersion::class, 'obligation_version_id');
    }

    public function calendarVersion(): BelongsTo
    {
        return $this->belongsTo(TaxDeadlineCalendarVersion::class, 'calendar_version_id');
    }

    public function competence(): BelongsTo
    {
        return $this->belongsTo(FiscalCompetence::class, 'competence_id');
    }

    public function conclusiveEvidence(): BelongsTo
    {
        return $this->belongsTo(TaxDeliveryEvidence::class, 'conclusive_evidence_id');
    }

    public function evidenceArtifact(): BelongsTo
    {
        return $this->belongsTo(FiscalEvidenceArtifact::class, 'evidence_artifact_id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(TaxDeliveryEvidence::class, 'projection_id');
    }

    public function lastValidRun(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'last_valid_run_id');
    }

    public function lastValidSnapshot(): BelongsTo
    {
        return $this->belongsTo(FiscalSnapshot::class, 'last_valid_snapshot_id');
    }

    public function pgdasdOperations(): HasMany
    {
        return $this->hasMany(PgdasdOperation::class, 'projection_id');
    }

    public function pgdasdArtifacts(): HasMany
    {
        return $this->hasMany(PgdasdArtifact::class, 'projection_id');
    }

    public function pgdasdRbt12Projections(): HasMany
    {
        return $this->hasMany(PgdasdRbt12Projection::class, 'projection_id');
    }

    public function pgdasdLastDeclarationOperation(): BelongsTo
    {
        return $this->belongsTo(PgdasdOperation::class, 'pgdasd_last_declaration_operation_id');
    }

    public function pgdasdLatestRbt12Projection(): BelongsTo
    {
        return $this->belongsTo(PgdasdRbt12Projection::class, 'pgdasd_latest_rbt12_projection_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(bool $withDeepLinks = true): array
    {
        $data = [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'obligation_definition_id' => $this->obligation_definition_id,
            'obligation_code' => $this->relationLoaded('obligation')
                ? $this->obligation?->code
                : null,
            'obligation_name' => $this->relationLoaded('obligation')
                ? $this->obligation?->name
                : null,
            'module_key' => $this->relationLoaded('obligation')
                ? $this->obligation?->module_key
                : null,
            'system_code' => $this->relationLoaded('obligation')
                ? $this->obligation?->system_code
                : null,
            'service_code' => $this->relationLoaded('obligation')
                ? $this->obligation?->service_code
                : null,
            'obligation_version_id' => $this->obligation_version_id,
            'calendar_version_id' => $this->calendar_version_id,
            'competence_id' => $this->competence_id,
            'period_key' => $this->period_key,
            'period_year' => $this->period_year,
            'period_month' => $this->period_month,
            'applicability' => $this->applicability?->value,
            'situation' => $this->situation?->value,
            'delivery_status' => $this->delivery_status?->value,
            'due_at' => $this->due_at?->toIso8601String(),
            'applicability_basis' => $this->applicability_basis,
            'is_open' => $this->is_open,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'conclusive_evidence_id' => $this->conclusive_evidence_id,
            'evidence_artifact_id' => $this->evidence_artifact_id,
            'last_valid_query_at' => $this->last_valid_query_at?->toIso8601String(),
            'obligation_version' => $this->relationLoaded('obligationVersion')
                ? $this->obligationVersion?->toPublicArray()
                : null,
            'calendar_version' => $this->relationLoaded('calendarVersion')
                ? $this->calendarVersion?->toPublicArray()
                : null,
        ];

        if ($withDeepLinks) {
            $data['deep_links'] = $this->deepLinks();
        }

        return $data;
    }

    /**
     * Deep-links para módulo de origem e evidência (sem paths internos de cofre).
     *
     * @return array<string, string|null>
     */
    public function deepLinks(): array
    {
        $module = $this->relationLoaded('obligation')
            ? $this->obligation?->module_key
            : null;

        return [
            'self' => '/api/v1/fiscal/declarations/'.$this->id,
            'module' => $module !== null ? '/api/v1/fiscal/declarations?module_key='.urlencode($module) : null,
            'evidence' => $this->evidence_artifact_id !== null
                ? '/api/v1/fiscal/evidence/'.$this->evidence_artifact_id.'/download'
                : null,
            'conclusive_evidence' => $this->conclusive_evidence_id !== null
                ? '/api/v1/fiscal/declarations/'.$this->id.'/evidences/'.$this->conclusive_evidence_id
                : null,
            'client' => '/api/v1/clients/'.$this->client_id,
            'competence' => $this->competence_id !== null
                ? '/api/v1/fiscal/declarations?competence_id='.$this->competence_id
                : null,
        ];
    }
}
