<?php

namespace App\Models;

use App\Enums\EsocialEventCode;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidência imutável de evento eSocial (S-5003, S-5013, S-1299).
 * Payload bruto no cofre; metadados sem material criptográfico.
 */
#[Fillable([
    'office_id',
    'client_id',
    'establishment_id',
    'run_id',
    'fiscal_evidence_artifact_id',
    'competence_period_key',
    'event_code',
    'event_version',
    'receipt_number',
    'establishment_cnpj',
    'content_sha256',
    'vault_object_id',
    'content_type',
    'byte_size',
    'source',
    'source_version',
    'occurred_at',
    'observed_at',
    'metadata',
    'is_quarantined',
    'quarantine_reason',
    'quarantined_at',
])]
class EsocialEventEvidence extends Model
{
    use BelongsToOffice;

    protected $table = 'esocial_event_evidences';

    protected function casts(): array
    {
        return [
            'event_code' => EsocialEventCode::class,
            'byte_size' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
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

    public function run(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'run_id');
    }

    public function fiscalEvidenceArtifact(): BelongsTo
    {
        return $this->belongsTo(FiscalEvidenceArtifact::class, 'fiscal_evidence_artifact_id');
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
            'run_id' => $this->run_id,
            'competence_period_key' => $this->competence_period_key,
            'event_code' => $this->event_code?->value,
            'event_label' => $this->event_code?->label(),
            'event_version' => $this->event_version,
            'receipt_number' => $this->receipt_number,
            'establishment_cnpj' => $this->establishment_cnpj,
            'content_sha256' => $this->content_sha256,
            'byte_size' => $this->byte_size,
            'source' => $this->source,
            'source_version' => $this->source_version,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'observed_at' => $this->observed_at?->toIso8601String(),
            'is_totalizer' => $this->event_code?->isTotalizer() ?? false,
            'is_closure' => $this->event_code?->isClosure() ?? false,
            'is_quarantined' => $this->is_quarantined,
            'quarantine_reason' => $this->quarantine_reason,
        ];
    }
}
