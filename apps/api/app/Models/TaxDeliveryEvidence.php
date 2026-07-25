<?php

namespace App\Models;

use App\Enums\TaxDeliveryEvidenceKind;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidência de entrega (recibo/protocolo oficial ou artefato interno não conclusivo).
 */
#[Fillable([
    'office_id',
    'projection_id',
    'kind',
    'protocol_number',
    'receipt_number',
    'is_conclusive',
    'source',
    'source_version',
    'observed_at',
    'evidence_artifact_id',
    'run_id',
    'payload_digest',
    'metadata',
])]
class TaxDeliveryEvidence extends Model
{
    use BelongsToOffice;

    /** @var string "evidence" é uncountable no pluralizer do Laravel. */
    protected $table = 'tax_delivery_evidences';

    protected function casts(): array
    {
        return [
            'kind' => TaxDeliveryEvidenceKind::class,
            'is_conclusive' => 'boolean',
            'observed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function projection(): BelongsTo
    {
        return $this->belongsTo(TaxObligationProjection::class, 'projection_id');
    }

    public function evidenceArtifact(): BelongsTo
    {
        return $this->belongsTo(FiscalEvidenceArtifact::class, 'evidence_artifact_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'run_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'projection_id' => $this->projection_id,
            'kind' => $this->kind?->value,
            'protocol_number' => $this->protocol_number,
            'receipt_number' => $this->receipt_number,
            'is_conclusive' => $this->is_conclusive,
            'source' => $this->source,
            'source_version' => $this->source_version,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'evidence_artifact_id' => $this->evidence_artifact_id,
            'run_id' => $this->run_id,
            'payload_digest' => $this->payload_digest,
            'deep_links' => [
                'projection' => '/api/v1/fiscal/declarations/'.$this->projection_id,
                'evidence_download' => $this->evidence_artifact_id !== null
                    ? '/api/v1/fiscal/evidence/'.$this->evidence_artifact_id.'/download'
                    : null,
                'run' => $this->run_id !== null
                    ? '/api/v1/fiscal/runs/'.$this->run_id
                    : null,
            ],
        ];
    }
}
