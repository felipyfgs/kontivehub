<?php

namespace App\Models;

use App\Casts\FiscalSourceProvenanceCast;
use App\Enums\FiscalVerificationState;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Evidência oficial imutável (bytes no cofre; metadados sem paths internos).
 */
#[Fillable([
    'office_id',
    'run_id',
    'vault_object_id',
    'content_sha256',
    'content_type',
    'byte_size',
    'source',
    'source_version',
    'source_provenance',
    'verification_state',
    'operation_key',
    'observed_at',
    'retention_until',
    'is_immutable',
    'metadata',
    'created_at',
])]
class FiscalEvidenceArtifact extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'source_provenance' => FiscalSourceProvenanceCast::class,
            'verification_state' => FiscalVerificationState::class,
            'observed_at' => 'immutable_datetime',
            'retention_until' => 'immutable_datetime',
            'is_immutable' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): bool {
            if ($model->is_immutable) {
                $protected = [
                    'office_id', 'run_id', 'vault_object_id', 'content_sha256',
                    'content_type', 'byte_size', 'source', 'source_version',
                    'observed_at', 'is_immutable',
                ];
                foreach ($protected as $col) {
                    if ($model->isDirty($col)) {
                        throw new LogicException(
                            "Artefato de evidência fiscal é imutável (coluna: {$col})."
                        );
                    }
                }
            }

            return true;
        });
    }

    /** @param Builder<self> $query */
    public function scopeOperationallyEligible(Builder $query): void
    {
        $query->where(function (Builder $verification): void {
            $verification->whereNull('verification_state')
                ->orWhere('verification_state', '!=', FiscalVerificationState::Rejected->value);
        });
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'run_id');
    }

    /**
     * Metadados internos/admin — não usar em resposta tenant de produto.
     * Preferir {@see toTenantDocumentArray()} ou FiscalDocumentDescriptorDto.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'run_id' => $this->run_id,
            'content_sha256' => $this->content_sha256,
            'content_type' => $this->content_type,
            'byte_size' => $this->byte_size,
            'source' => $this->source,
            'source_version' => $this->source_version,
            'operation_key' => $this->operation_key,
            'source_provenance' => $this->source_provenance?->value,
            'verification_state' => $this->verification_state?->value,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'retention_until' => $this->retention_until?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Metadados sanitizados para UI tenant — sem hash, operation_key, run_id, vault ou path.
     *
     * @return array{
     *   id: int|string|null,
     *   content_type: string|null,
     *   byte_size: int|null,
     *   source: string|null,
     *   observed_at: string|null,
     *   created_at: string|null
     * }
     */
    public function toTenantDocumentArray(): array
    {
        return [
            'id' => $this->id,
            'content_type' => $this->content_type,
            'byte_size' => $this->byte_size,
            'source' => $this->source,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
