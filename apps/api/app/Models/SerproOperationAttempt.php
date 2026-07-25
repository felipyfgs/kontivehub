<?php

namespace App\Models;

use App\Enums\SerproAttemptState;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'environment',
    'operation_key',
    'entity_key',
    'idempotency_key',
    'request_tag',
    'correlation_id',
    'attempt_state',
    'reservation_id',
    'client_id',
    'success',
    'http_status',
    'error_code',
    'error_message',
    'simulated',
    'latency_ms',
    'source_provenance',
    'business_status',
    'functional_route',
    'mensagens',
    'dados',
    'body',
    'headers',
    'reserved_at',
    'dispatched_at',
    'acknowledged_at',
    'reconciled_at',
])]
#[Hidden(['mensagens', 'dados', 'body', 'headers'])]
class SerproOperationAttempt extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'attempt_state' => SerproAttemptState::class,
            'success' => 'boolean',
            'simulated' => 'boolean',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'mensagens' => 'array',
            'dados' => 'array',
            'body' => 'array',
            'headers' => 'array',
            'reserved_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(SerproApiUsageReservation::class, 'reservation_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isTerminal(): bool
    {
        $state = $this->attempt_state;

        return $state instanceof SerproAttemptState && $state->isTerminal();
    }

    public function isInFlight(): bool
    {
        $state = $this->attempt_state;

        return $state instanceof SerproAttemptState && $state->isInFlight();
    }
}
