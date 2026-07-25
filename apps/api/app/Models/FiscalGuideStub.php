<?php

namespace App\Models;

use App\Enums\FiscalGuideEmissionStatus;
use App\Enums\FiscalGuidePaymentStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stub de guia fiscal (DAS etc.) — emissão separada de pagamento.
 * Task 11 migrará para o modelo pleno de tax-guide-management.
 */
#[Fillable([
    'office_id',
    'client_id',
    'run_id',
    'system_code',
    'service_code',
    'operation_code',
    'regime_family',
    'period_key',
    'document_number',
    'due_date',
    'amount',
    'emission_status',
    'payment_status',
    'is_external_call',
    'metadata',
    'is_quarantined',
    'quarantine_reason',
    'quarantined_at',
])]
class FiscalGuideStub extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'emission_status' => FiscalGuideEmissionStatus::class,
            'payment_status' => FiscalGuidePaymentStatus::class,
            'is_external_call' => 'boolean',
            'metadata' => 'array',
            'is_quarantined' => 'boolean',
            'quarantined_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeOperationallyEligible(Builder $query): void
    {
        $query->where('is_quarantined', false);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
            'run_id' => $this->run_id,
            'system_code' => $this->system_code,
            'service_code' => $this->service_code,
            'operation_code' => $this->operation_code,
            'regime_family' => $this->regime_family,
            'period_key' => $this->period_key,
            'document_number' => $this->document_number,
            'due_date' => $this->due_date?->toDateString(),
            'amount' => $this->amount,
            'emission_status' => $this->emission_status?->value,
            'payment_status' => $this->payment_status?->value,
            'is_external_call' => $this->is_external_call,
            'is_quarantined' => $this->is_quarantined,
            'quarantine_reason' => $this->quarantine_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
