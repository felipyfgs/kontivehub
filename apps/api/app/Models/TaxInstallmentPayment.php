<?php

namespace App\Models;

use App\Enums\TaxInstallmentModality;
use App\Enums\TaxInstallmentPaymentStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'order_id',
    'parcel_id',
    'modality',
    'status',
    'amount_cents',
    'paid_at',
    'payment_ref',
    'evidence_sha256',
    'run_id',
    'metadata',
])]
class TaxInstallmentPayment extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'modality' => TaxInstallmentModality::class,
            'status' => TaxInstallmentPaymentStatus::class,
            'amount_cents' => 'integer',
            'paid_at' => 'immutable_datetime',
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

    public function parcel(): BelongsTo
    {
        return $this->belongsTo(TaxInstallmentParcel::class, 'parcel_id');
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
            'order_id' => $this->order_id,
            'parcel_id' => $this->parcel_id,
            'modality' => $this->modality?->value ?? $this->getAttribute('modality'),
            'status' => $this->status?->value ?? $this->getAttribute('status'),
            'amount_cents' => $this->amount_cents,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_ref' => $this->payment_ref,
        ];
    }
}
