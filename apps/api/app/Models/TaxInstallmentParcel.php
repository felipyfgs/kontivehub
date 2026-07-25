<?php

namespace App\Models;

use App\Enums\TaxInstallmentModality;
use App\Enums\TaxInstallmentParcelStatus;
use App\Enums\TaxInstallmentPaymentStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'order_id',
    'modality',
    'parcel_key',
    'parcel_number',
    'status',
    'source_status',
    'due_at',
    'amount_cents',
    'document_available',
    'payment_status',
    'paid_at',
    'payment_id',
    'tax_guide_id',
    'logical_key',
    'metadata',
])]
class TaxInstallmentParcel extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'modality' => TaxInstallmentModality::class,
            'status' => TaxInstallmentParcelStatus::class,
            'payment_status' => TaxInstallmentPaymentStatus::class,
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'amount_cents' => 'integer',
            'parcel_number' => 'integer',
            'document_available' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(TaxInstallmentOrder::class, 'order_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(TaxInstallmentPayment::class, 'payment_id');
    }

    public function taxGuide(): BelongsTo
    {
        return $this->belongsTo(TaxGuide::class, 'tax_guide_id');
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
            'order_id' => $this->order_id,
            'modality' => $this->modality?->value ?? $this->getAttribute('modality'),
            'parcel_key' => $this->parcel_key,
            'parcel_number' => $this->parcel_number,
            'status' => $this->status?->value ?? $this->getAttribute('status'),
            'source_status' => $this->source_status,
            'due_at' => $this->due_at?->toIso8601String(),
            'amount_cents' => $this->amount_cents,
            'document_available' => $this->document_available,
            'payment_status' => $this->payment_status?->value ?? $this->getAttribute('payment_status'),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'tax_guide_id' => $this->tax_guide_id,
            'logical_key' => $this->logical_key,
        ];
    }
}
