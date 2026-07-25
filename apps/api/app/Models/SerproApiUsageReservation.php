<?php

namespace App\Models;

use App\Enums\SerproConsumptionClass;
use App\Enums\SerproUsageReservationStatus;
use App\Enums\SerproUsageResult;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Reserva/execução de orçamento antes da chamada SERPRO.
 * Transições de status permitidas; valores de custo/classe não são reescritos após create.
 */
#[Fillable([
    'office_id',
    'idempotency_key',
    'client_id',
    'contributor_ref',
    'system_code',
    'service_code',
    'operation_code',
    'operation_key',
    'is_simulated',
    'consumption_class',
    'quantity',
    'is_essential',
    'status',
    'correlation_id',
    'request_tag',
    'functional_route',
    'price_version_id',
    'estimated_cost_micros',
    'shadow_mode',
    'would_block',
    'block_reason',
    'result',
    'http_status',
    'latency_ms',
    'possibly_billable',
    'reserved_at',
    'finalized_at',
    'environment',
    'serpro_contract_id',
    'attempt_state',
    'catalog_revision',
    'price_revision',
    'remote_state',
    'durable_result_ref',
    'segregation_class',
])]
class SerproApiUsageReservation extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'consumption_class' => SerproConsumptionClass::class,
            'status' => SerproUsageReservationStatus::class,
            'result' => SerproUsageResult::class,
            'quantity' => 'integer',
            'is_essential' => 'boolean',
            'is_simulated' => 'boolean',
            'estimated_cost_micros' => 'integer',
            'shadow_mode' => 'boolean',
            'would_block' => 'boolean',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'possibly_billable' => 'boolean',
            'reserved_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function priceVersion(): BelongsTo
    {
        return $this->belongsTo(SerproPriceVersion::class, 'price_version_id');
    }

    public function entry(): HasOne
    {
        return $this->hasOne(SerproApiUsageEntry::class, 'reservation_id');
    }

    public function isOpen(): bool
    {
        return $this->status === SerproUsageReservationStatus::Reserved;
    }
}
