<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'projection_id',
    'operation_id',
    'fiscal_evidence_artifact_id',
    'declaration_number',
    'das_number',
    'kind',
    'filename',
    'content_type',
    'observed_at',
    'source_run_id',
    'metadata',
])]
class PgdasdArtifact extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'observed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function projection(): BelongsTo
    {
        return $this->belongsTo(TaxObligationProjection::class, 'projection_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(PgdasdOperation::class, 'operation_id');
    }

    public function evidenceArtifact(): BelongsTo
    {
        return $this->belongsTo(FiscalEvidenceArtifact::class, 'fiscal_evidence_artifact_id');
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'source_run_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toTenantDocumentArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'filename' => $this->filename,
            'content_type' => $this->content_type,
            'observed_at' => $this->observed_at?->toIso8601String(),
            // Caminho same-origin; os bytes continuam restritos ao endpoint
            // autenticado e tenant-scoped.
            'download_path' => '/api/v1/fiscal/simples-mei/pgdasd/artifacts/'.$this->id.'/download',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $evidence = $this->relationLoaded('evidenceArtifact') ? $this->evidenceArtifact : null;

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'period_key' => is_array($this->metadata) ? ($this->metadata['period_key'] ?? null) : null,
            'declaration_number' => $this->declaration_number,
            'das_number' => $this->das_number,
            'filename' => $this->filename,
            'content_type' => $this->content_type,
            'byte_size' => $evidence?->byte_size,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'download_path' => '/api/v1/fiscal/simples-mei/pgdasd/artifacts/'.$this->id.'/download',
        ];
    }
}
