<?php

namespace App\Models;

use App\Enums\DctfwebMutationStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'competence_id',
    'system_code',
    'service_code',
    'operation_code',
    'period_key',
    'idempotency_key',
    'status',
    'correlation_id',
    'sent_at',
    'resolved_at',
    'blocked_retry_until',
    'error_code',
    'error_message',
    'metadata',
])]
class DctfwebMutationAttempt extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'status' => DctfwebMutationStatus::class,
            'sent_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'blocked_retry_until' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
            'competence_id' => $this->competence_id,
            'system_code' => $this->system_code,
            'service_code' => $this->service_code,
            'operation_code' => $this->operation_code,
            'period_key' => $this->period_key,
            'status' => $this->status?->value,
            'correlation_id' => $this->correlation_id,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'blocked_retry_until' => $this->blocked_retry_until?->toIso8601String(),
            'error_code' => $this->error_code,
        ];
    }
}
