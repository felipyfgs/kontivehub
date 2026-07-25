<?php

namespace App\Models;

use App\Enums\SerproConsumptionClass;
use App\Enums\SerproUsageResult;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada imutável do ledger de consumo SERPRO.
 * Sem updated_at; proibido update de valores após insert.
 */
#[Fillable([
    'office_id',
    'reservation_id',
    'idempotency_key',
    'client_id',
    'contributor_ref',
    'system_code',
    'service_code',
    'operation_code',
    'operation_key',
    'consumption_class',
    'quantity',
    'result',
    'correlation_id',
    'request_tag',
    'functional_route',
    'is_simulated',
    'price_version_id',
    'estimated_cost_micros',
    'is_billable_attempt',
    'latency_ms',
    'http_status',
    'shadow_mode',
    'occurred_at',
    'created_at',
    'environment',
    'serpro_contract_id',
    'attempt_state',
    'catalog_revision',
    'price_revision',
    'remote_state',
    'segregation_class',
])]
class SerproApiUsageEntry extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'consumption_class' => SerproConsumptionClass::class,
            'result' => SerproUsageResult::class,
            'quantity' => 'integer',
            'estimated_cost_micros' => 'integer',
            'is_billable_attempt' => 'boolean',
            'is_simulated' => 'boolean',
            'latency_ms' => 'integer',
            'http_status' => 'integer',
            'shadow_mode' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // Imutabilidade: bloquear updates de colunas de valor após create.
        static::updating(function (self $entry): bool {
            $protected = [
                'office_id',
                'reservation_id',
                'idempotency_key',
                'client_id',
                'contributor_ref',
                'system_code',
                'service_code',
                'operation_code',
                'consumption_class',
                'quantity',
                'result',
                'correlation_id',
                'price_version_id',
                'estimated_cost_micros',
                'is_billable_attempt',
                'latency_ms',
                'http_status',
                'shadow_mode',
                'occurred_at',
            ];

            foreach ($protected as $col) {
                if ($entry->isDirty($col)) {
                    throw new \LogicException(
                        "Entrada de ledger serpro_api_usage_entries é imutável (coluna: {$col})."
                    );
                }
            }

            return false;
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(SerproApiUsageReservation::class, 'reservation_id');
    }

    public function priceVersion(): BelongsTo
    {
        return $this->belongsTo(SerproPriceVersion::class, 'price_version_id');
    }

    /**
     * Payload sanitizado para API tenant (sem custo global bruto não contratado).
     *
     * @return array<string, mixed>
     */
    public function toTenantArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'system_code' => $this->system_code,
            'service_code' => $this->service_code,
            'operation_code' => $this->operation_code,
            'consumption_class' => $this->consumption_class->value,
            'quantity' => $this->quantity,
            'result' => $this->result->value,
            'correlation_id' => $this->correlation_id,
            'estimated_cost_micros' => $this->estimated_cost_micros,
            'is_billable_attempt' => $this->is_billable_attempt,
            'latency_ms' => $this->latency_ms,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }

    /**
     * Payload para PLATFORM_ADMIN (consolidação; sem payload fiscal).
     *
     * @return array<string, mixed>
     */
    public function toPlatformArray(): array
    {
        return array_merge($this->toTenantArray(), [
            'price_version_id' => $this->price_version_id,
            'http_status' => $this->http_status,
            'shadow_mode' => $this->shadow_mode,
            'reservation_id' => $this->reservation_id,
            'contributor_ref' => $this->contributor_ref,
        ]);
    }
}
