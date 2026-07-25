<?php

namespace App\Models;

use App\Enums\MonitorCommercialDispatchState;
use App\Enums\MonitorCommercialOrigin;
use App\Models\Concerns\BelongsToOffice;
use Database\Factories\MonitorCommercialLedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unidade comercial de consulta de monitor SERPRO (ledger separado do técnico).
 * Identidade protegida; dispatch_state pode avançar.
 */
#[Fillable([
    'office_id',
    'client_id',
    'monitor_key',
    'origin',
    'dispatch_state',
    'quota_units',
    'period_starts_at',
    'period_ends_at',
    'period_key',
    'idempotency_key',
    'technical_correlation_id',
    'technical_usage_entry_id',
    'dispatched_at',
    'completed_at',
    'blocked_reason',
    'metadata',
])]
class MonitorCommercialLedgerEntry extends Model
{
    /** @use HasFactory<MonitorCommercialLedgerEntryFactory> */
    use BelongsToOffice, HasFactory;

    protected function casts(): array
    {
        return [
            'origin' => MonitorCommercialOrigin::class,
            'dispatch_state' => MonitorCommercialDispatchState::class,
            'quota_units' => 'integer',
            'period_starts_at' => 'immutable_datetime',
            'period_ends_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            $protected = [
                'office_id',
                'client_id',
                'monitor_key',
                'origin',
                'period_starts_at',
                'period_ends_at',
                'period_key',
                'idempotency_key',
            ];

            foreach ($protected as $col) {
                if ($entry->isDirty($col)) {
                    throw new \LogicException(
                        "Entrada comercial monitor_commercial_ledger_entries é imutável na identidade (coluna: {$col})."
                    );
                }
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function consumesQuota(): bool
    {
        return $this->quota_units > 0 && $this->dispatch_state->consumesQuota();
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
            'monitor_key' => $this->monitor_key,
            'origin' => $this->origin->value,
            'dispatch_state' => $this->dispatch_state->value,
            'quota_units' => $this->quota_units,
            'period_key' => $this->period_key,
            'period_starts_at' => $this->period_starts_at?->toIso8601String(),
            'period_ends_at' => $this->period_ends_at?->toIso8601String(),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'blocked_reason' => $this->blocked_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    protected static function newFactory(): MonitorCommercialLedgerEntryFactory
    {
        return MonitorCommercialLedgerEntryFactory::new();
    }
}
