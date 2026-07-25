<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Confirmação oficial de pagamento — independente de emissão/download.
 */
#[Fillable([
    'office_id',
    'tax_guide_id',
    'tax_guide_version_id',
    'source',
    'external_id',
    'amount_cents',
    'currency',
    'paid_at',
    'content_sha256',
    'vault_object_id',
    'content_type',
    'byte_size',
    'evidence_digest',
    'metadata',
    'recorded_by',
    'created_at',
])]
class TaxGuidePaymentConfirmation extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'byte_size' => 'integer',
            'paid_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(TaxGuide::class, 'tax_guide_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TaxGuideVersion::class, 'tax_guide_version_id');
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
            'tax_guide_version_id' => $this->tax_guide_version_id,
            'source' => $this->source,
            'external_id' => $this->external_id,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'content_sha256' => $this->content_sha256,
            'content_type' => $this->content_type,
            'byte_size' => $this->byte_size,
            'created_at' => $this->created_at?->toIso8601String(),
            // vault_object_id NUNCA exposto
        ];
    }
}
