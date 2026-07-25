<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'job_type',
    'office_id',
    'client_id',
    'environment',
    'status',
    'correlation_id',
    'attempt',
    'cursor',
    'pages_done',
    'error_code',
    'error_message',
    'flag_checked_at_dispatch',
    'flag_checked_at_handle',
    'progress',
    'started_at',
    'finished_at',
    'next_retry_at',
])]
class SerproAsyncJobRun extends Model
{
    use BelongsToOffice;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_SUCCEEDED = 'SUCCEEDED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_BLOCKED = 'BLOCKED';

    public const STATUS_RATE_LIMITED = 'RATE_LIMITED';

    protected function casts(): array
    {
        return [
            'flag_checked_at_dispatch' => 'boolean',
            'flag_checked_at_handle' => 'boolean',
            'progress' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'next_retry_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'job_type' => $this->job_type,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'environment' => $this->environment,
            'status' => $this->status,
            'correlation_id' => $this->correlation_id,
            'attempt' => $this->attempt,
            'cursor' => $this->cursor,
            'pages_done' => $this->pages_done,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'flag_checked_at_dispatch' => $this->flag_checked_at_dispatch,
            'flag_checked_at_handle' => $this->flag_checked_at_handle,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
        ];
    }
}
