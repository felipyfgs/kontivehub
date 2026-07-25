<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'employer_hash',
    'environment',
    'operation',
    'access_date',
    'status',
    'http_status',
    'official_code',
    'retryable',
    'correlation_id',
    'finished_at',
])]
final class EsocialBxAccessLedger extends Model
{
    use BelongsToOffice;

    protected $hidden = ['employer_hash'];

    protected function casts(): array
    {
        return [
            'access_date' => 'immutable_date',
            'retryable' => 'boolean',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'environment' => $this->environment,
            'operation' => $this->operation,
            'status' => $this->status,
            'http_status' => $this->http_status,
            'official_code' => $this->official_code,
            'retryable' => $this->retryable,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
