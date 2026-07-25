<?php

namespace App\Models;

use App\Enums\PgmeiDebtState;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'office_id',
    'client_id',
    'calendar_year',
    'debt_state',
    'digest',
    'items_count',
    'total_cents',
    'observed_at',
    'source_run_id',
    'source_snapshot_id',
    'metadata',
])]
class PgmeiDebtObservation extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'calendar_year' => 'integer',
            'debt_state' => PgmeiDebtState::class,
            'items_count' => 'integer',
            'total_cents' => 'integer',
            'observed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Observações PGMEI são imutáveis.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Observações PGMEI não podem ser removidas diretamente.');
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'source_run_id');
    }

    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(FiscalSnapshot::class, 'source_snapshot_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PgmeiDebtItem::class, 'observation_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'calendar_year' => (int) $this->calendar_year,
            'debt_state' => $this->debt_state?->value ?? $this->getRawOriginal('debt_state'),
            'items_count' => (int) $this->items_count,
            'total_cents' => (int) $this->total_cents,
            'observed_at' => $this->observed_at?->toIso8601String(),
        ];
    }
}
