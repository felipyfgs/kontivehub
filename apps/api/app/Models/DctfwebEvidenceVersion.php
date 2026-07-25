<?php

namespace App\Models;

use App\Enums\DctfwebArtifactKind;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Versão imutável de evidência (recibo/XML/relatório/DARF).
 * Retificação cria nova versão — nunca sobrescreve a anterior.
 */
#[Fillable([
    'office_id',
    'client_id',
    'declaration_id',
    'competence_id',
    'run_id',
    'evidence_artifact_id',
    'artifact_kind',
    'version',
    'content_sha256',
    'is_current',
    'declaration_type',
    'source_version',
    'is_retification',
    'observed_at',
    'metadata',
    'created_at',
])]
class DctfwebEvidenceVersion extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'artifact_kind' => DctfwebArtifactKind::class,
            'version' => 'integer',
            'is_current' => 'boolean',
            'is_retification' => 'boolean',
            'observed_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): bool {
            // is_current pode ser desligado ao criar sucessor; bytes/sha/version imutáveis.
            $protected = [
                'office_id', 'client_id', 'declaration_id', 'competence_id',
                'run_id', 'evidence_artifact_id', 'artifact_kind', 'version',
                'content_sha256', 'declaration_type', 'source_version',
                'is_retification', 'observed_at',
            ];
            foreach ($protected as $col) {
                if ($model->isDirty($col)) {
                    throw new LogicException(
                        "Versão de evidência DCTFWeb é imutável (coluna: {$col})."
                    );
                }
            }

            return true;
        });
    }

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(DctfwebDeclaration::class, 'declaration_id');
    }

    public function artifact(): BelongsTo
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
            'client_id' => $this->client_id,
            'declaration_id' => $this->declaration_id,
            'competence_id' => $this->competence_id,
            'run_id' => $this->run_id,
            'evidence_artifact_id' => $this->evidence_artifact_id,
            'artifact_kind' => $this->artifact_kind?->value,
            'version' => $this->version,
            'content_sha256' => $this->content_sha256,
            'is_current' => $this->is_current,
            'declaration_type' => $this->declaration_type,
            'source_version' => $this->source_version,
            'is_retification' => $this->is_retification,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
