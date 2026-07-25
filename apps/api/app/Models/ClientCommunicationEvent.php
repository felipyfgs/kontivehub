<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento futuro append-only; payload remoto nunca é persistido, apenas digest/metadados sanitizados.
 */
#[Fillable([
    'office_id',
    'dispatch_id',
    'status',
    'occurred_at',
    'received_at',
    'source',
    'provider_event_id',
    'payload_digest',
    'metadata',
    'created_at',
])]
class ClientCommunicationEvent extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(ClientCommunicationDispatch::class, 'dispatch_id');
    }
}
