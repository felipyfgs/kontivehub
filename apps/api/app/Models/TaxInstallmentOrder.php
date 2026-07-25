<?php

namespace App\Models;

use App\Enums\TaxInstallmentModality;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'client_id',
    'run_id',
    'snapshot_id',
    'modality',
    'regime',
    'external_order_id',
    'situation',
    'source_status',
    'requested_at',
    'consolidated_at',
    'parcel_count',
    'total_amount_cents',
    'source_system',
    'source_service',
    'source_operation',
    'evidence_sha256',
    'observed_at',
    'metadata',
])]
class TaxInstallmentOrder extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'modality' => TaxInstallmentModality::class,
            'requested_at' => 'immutable_datetime',
            'consolidated_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
            'parcel_count' => 'integer',
            'total_amount_cents' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'run_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(FiscalSnapshot::class, 'snapshot_id');
    }

    public function parcels(): HasMany
    {
        return $this->hasMany(TaxInstallmentParcel::class, 'order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TaxInstallmentPayment::class, 'order_id');
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
            'modality' => $this->modality?->value ?? $this->getAttribute('modality'),
            'regime' => $this->regime,
            'external_order_id' => $this->external_order_id,
            'situation' => $this->situation,
            'source_status' => $this->source_status,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'consolidated_at' => $this->consolidated_at?->toIso8601String(),
            'parcel_count' => $this->parcel_count,
            'total_amount_cents' => $this->total_amount_cents,
            'source_system' => $this->source_system,
            'source_service' => $this->source_service,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
