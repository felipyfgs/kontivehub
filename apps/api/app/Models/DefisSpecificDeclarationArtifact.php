<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Descritor DEFIS 144; declaração e recibo permanecem exclusivamente no cofre. */
#[Fillable([
    'office_id', 'client_id', 'defis_declaration_reference_id', 'kind', 'fiscal_evidence_artifact_id',
    'source_run_id', 'source_provenance', 'observed_at', 'filename', 'content_type', 'digest',
])]
class DefisSpecificDeclarationArtifact extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime'];
    }

    public function evidenceArtifact(): BelongsTo
    {
        return $this->belongsTo(FiscalEvidenceArtifact::class, 'fiscal_evidence_artifact_id');
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'filename' => $this->filename,
            'content_type' => $this->content_type,
            'byte_size' => $this->relationLoaded('evidenceArtifact') ? $this->evidenceArtifact?->byte_size : null,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'download_path' => '/api/v1/fiscal/simples-mei/defis/specific-artifacts/'.$this->id.'/download',
        ];
    }
}
