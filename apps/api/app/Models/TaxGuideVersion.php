<?php

namespace App\Models;

use App\Enums\TaxGuideEmissionStatus;
use App\Enums\TaxGuideRiskLevel;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'office_id',
    'tax_guide_id',
    'version_number',
    'is_current',
    'emission_status',
    'replaces_version_id',
    'superseded_by_version_id',
    'identifier_code',
    'amount_cents',
    'currency',
    'due_at',
    'valid_until',
    'content_sha256',
    'vault_object_id',
    'content_type',
    'byte_size',
    'idempotency_key',
    'correlation_id',
    'usage_reservation_id',
    'remote_protocol',
    'risk_level',
    'confirmation_summary',
    'confirmed_by_user_id',
    'confirmed_at',
    'issued_by',
    'sent_at',
    'finished_at',
    'reconcile_after',
    'reconcile_attempts',
    'error_code',
    'error_message',
    'metadata',
])]
class TaxGuideVersion extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'emission_status' => TaxGuideEmissionStatus::class,
            'risk_level' => TaxGuideRiskLevel::class,
            'amount_cents' => 'integer',
            'byte_size' => 'integer',
            'due_at' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'confirmation_summary' => 'array',
            'confirmed_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'reconcile_after' => 'immutable_datetime',
            'reconcile_attempts' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): bool {
            // Artefato no cofre é imutável após CONFIRMED com bytes.
            if (
                $model->getOriginal('emission_status') === TaxGuideEmissionStatus::Confirmed->value
                || $model->getOriginal('emission_status') === TaxGuideEmissionStatus::Confirmed
            ) {
                $protected = [
                    'content_sha256', 'vault_object_id', 'content_type', 'byte_size',
                    'identifier_code', 'amount_cents', 'due_at',
                ];
                foreach ($protected as $col) {
                    if ($model->isDirty($col)) {
                        throw new LogicException(
                            "Versão de guia confirmada é imutável no artefato (coluna: {$col})."
                        );
                    }
                }
            }

            return true;
        });
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(TaxGuide::class, 'tax_guide_id');
    }

    public function replacesVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_version_id');
    }

    public function supersededByVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_version_id');
    }

    public function downloadTokens(): HasMany
    {
        return $this->hasMany(TaxGuideDownloadToken::class, 'tax_guide_version_id');
    }

    public function hasStoredDocument(): bool
    {
        return $this->vault_object_id !== null
            && $this->content_sha256 !== null
            && $this->byte_size > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'tax_guide_id' => $this->tax_guide_id,
            'version_number' => $this->version_number,
            'is_current' => $this->is_current,
            'emission_status' => $this->emission_status?->value ?? $this->emission_status,
            'replaces_version_id' => $this->replaces_version_id,
            'superseded_by_version_id' => $this->superseded_by_version_id,
            'identifier_code' => $this->identifier_code,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'due_at' => $this->due_at?->toIso8601String(),
            'valid_until' => $this->valid_until?->toIso8601String(),
            'content_sha256' => $this->content_sha256,
            'content_type' => $this->content_type,
            'byte_size' => $this->byte_size,
            'has_document' => $this->hasStoredDocument(),
            'idempotency_key' => $this->idempotency_key,
            'correlation_id' => $this->correlation_id,
            'remote_protocol' => $this->remote_protocol,
            'risk_level' => $this->risk_level?->value ?? $this->risk_level,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'reconcile_after' => $this->reconcile_after?->toIso8601String(),
            'reconcile_attempts' => $this->reconcile_attempts,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // vault_object_id NUNCA exposto
        ];
    }
}
