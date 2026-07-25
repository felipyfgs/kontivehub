<?php

namespace App\Models;

use App\Casts\FiscalSourceProvenanceCast;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalVerificationState;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Snapshot imutável de situação fiscal (finalizado não se altera silenciosamente).
 */
#[Fillable([
    'office_id',
    'run_id',
    'client_id',
    'competence_id',
    'evidence_artifact_id',
    'system_code',
    'service_code',
    'operation_code',
    'operation_key',
    'source_provenance',
    'verification_state',
    'situation',
    'coverage',
    'version',
    'is_current',
    'normalized',
    'observed_at',
    'created_at',
])]
class FiscalSnapshot extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'situation' => FiscalSituation::class,
            'coverage' => FiscalCoverage::class,
            'source_provenance' => FiscalSourceProvenanceCast::class,
            'verification_state' => FiscalVerificationState::class,
            'version' => 'integer',
            'is_current' => 'boolean',
            'normalized' => 'array',
            'observed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Snapshot pode representar "estado atual" fiscal oficial?
     */
    public function representsOfficialCurrentState(): bool
    {
        $provenance = $this->source_provenance;
        if ($provenance === null) {
            return false;
        }
        if ($provenance instanceof FiscalSourceProvenance) {
            return $provenance->isOfficialFiscalState() && (bool) $this->is_current;
        }

        return $provenance === 'SERPRO_REAL' && (bool) $this->is_current;
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): bool {
            // is_current pode ser desligado ao criar snapshot sucessor; demais campos imutáveis.
            $protected = [
                'office_id', 'run_id', 'client_id', 'competence_id', 'evidence_artifact_id',
                'system_code', 'service_code', 'operation_code', 'situation', 'coverage',
                'version', 'normalized', 'observed_at',
            ];
            foreach ($protected as $col) {
                if ($model->isDirty($col)) {
                    throw new LogicException(
                        "Snapshot fiscal finalizado é imutável (coluna: {$col})."
                    );
                }
            }

            return true;
        });
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'run_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function competence(): BelongsTo
    {
        return $this->belongsTo(FiscalCompetence::class, 'competence_id');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(FiscalEvidenceArtifact::class, 'evidence_artifact_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(FiscalFinding::class, 'snapshot_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'run_id' => $this->run_id,
            'client_id' => $this->client_id,
            'competence_id' => $this->competence_id,
            'evidence_artifact_id' => $this->evidence_artifact_id,
            'system_code' => $this->system_code,
            'service_code' => $this->service_code,
            'operation_code' => $this->operation_code,
            'operation_key' => $this->operation_key,
            'source_provenance' => $this->source_provenance instanceof \BackedEnum
                ? $this->source_provenance->value
                : $this->source_provenance,
            'verification_state' => $this->verification_state instanceof \BackedEnum
                ? $this->verification_state->value
                : $this->verification_state,
            'situation' => $this->situation?->value,
            'coverage' => $this->coverage?->value,
            'version' => $this->version,
            'is_current' => $this->is_current,
            'normalized' => $this->normalized,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
