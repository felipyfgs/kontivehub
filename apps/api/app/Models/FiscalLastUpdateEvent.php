<?php

namespace App\Models;

use App\Enums\FiscalEventStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'system_code',
    'service_code',
    'event_type',
    'event_external_id',
    'event_hash',
    'payload_digest',
    'status',
    'occurred_at',
    'received_at',
    'processed_at',
    'directed_run_id',
    'metadata',
])]
class FiscalLastUpdateEvent extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'status' => FiscalEventStatus::class,
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function directedRun(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'directed_run_id');
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
            'system_code' => $this->system_code,
            'service_code' => $this->service_code,
            'event_type' => $this->event_type,
            'event_external_id' => $this->event_external_id,
            'status' => $this->status?->value,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'directed_run_id' => $this->directed_run_id,
        ];
    }
}
