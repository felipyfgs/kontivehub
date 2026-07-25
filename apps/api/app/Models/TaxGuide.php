<?php

namespace App\Models;

use App\Enums\TaxGuidePaymentStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Identidade lógica de guia (tenant-scoped).
 * Versões/artefatos em tax_guide_versions; pagamento é eixo independente.
 */
#[Fillable([
    'office_id',
    'client_id',
    'establishment_id',
    'system_code',
    'service_code',
    'operation_code',
    'competence_period_key',
    'debit_ref',
    'logical_key',
    'current_version_id',
    'payment_status',
    'payment_confirmed_at',
    'payment_source',
    'payment_external_id',
    'amount_cents',
    'currency',
    'due_at',
    'identifier_code',
    'created_by',
    'metadata',
])]
class TaxGuide extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'payment_status' => TaxGuidePaymentStatus::class,
            'payment_confirmed_at' => 'immutable_datetime',
            'amount_cents' => 'integer',
            'due_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TaxGuideVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TaxGuideVersion::class, 'tax_guide_id');
    }

    public function paymentConfirmations(): HasMany
    {
        return $this->hasMany(TaxGuidePaymentConfirmation::class, 'tax_guide_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $current = $this->relationLoaded('currentVersion')
            ? $this->currentVersion
            : $this->currentVersion()->first();

        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'establishment_id' => $this->establishment_id,
            'system_code' => $this->system_code,
            'service_code' => $this->service_code,
            'operation_code' => $this->operation_code,
            'competence_period_key' => $this->competence_period_key,
            'debit_ref' => $this->debit_ref,
            'logical_key' => $this->logical_key,
            'payment_status' => $this->payment_status?->value,
            'payment_confirmed_at' => $this->payment_confirmed_at?->toIso8601String(),
            'payment_source' => $this->payment_source,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'due_at' => $this->due_at?->toIso8601String(),
            'identifier_code' => $this->identifier_code,
            'current_version_id' => $this->current_version_id,
            'current_version' => $current?->toPublicArray(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
