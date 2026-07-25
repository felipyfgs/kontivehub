<?php

namespace App\Models;

use App\Enums\CaptureChannel;
use App\Models\Concerns\BelongsToOffice;
use App\Support\LogSanitizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transição auditável de channel_sync_cursors (status/NSU/cStat/correlação).
 * Metadados sempre sanitizados — sem material fiscal bruto.
 */
#[Fillable([
    'office_id', 'channel_sync_cursor_id', 'channel', 'event',
    'from_status', 'to_status', 'from_last_nsu', 'to_last_nsu',
    'last_cstat', 'correlation_id', 'metadata', 'occurred_at',
])]
class ChannelSyncCursorTransition extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'channel' => CaptureChannel::class,
            'from_last_nsu' => 'integer',
            'to_last_nsu' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function cursor(): BelongsTo
    {
        return $this->belongsTo(ChannelSyncCursor::class, 'channel_sync_cursor_id');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        ChannelSyncCursor $cursor,
        string $event,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $correlationId = null,
        array $metadata = [],
    ): self {
        $safeMeta = LogSanitizer::redact($metadata);

        return self::query()->create([
            'office_id' => $cursor->office_id,
            'channel_sync_cursor_id' => $cursor->id,
            'channel' => $cursor->channel instanceof CaptureChannel
                ? $cursor->channel->value
                : (string) $cursor->channel,
            'event' => mb_substr($event, 0, 60),
            'from_status' => $fromStatus,
            'to_status' => $toStatus ?? ($cursor->status?->value ?? (string) $cursor->status),
            'from_last_nsu' => null,
            'to_last_nsu' => (int) $cursor->last_nsu,
            'last_cstat' => $cursor->last_cstat,
            'correlation_id' => $correlationId !== null ? mb_substr($correlationId, 0, 64) : null,
            'metadata' => $safeMeta === [] ? null : $safeMeta,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'cursor_id' => $this->channel_sync_cursor_id,
            'channel' => $this->channel instanceof CaptureChannel
                ? $this->channel->value
                : (string) $this->channel,
            'event' => $this->event,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'from_last_nsu' => $this->from_last_nsu,
            'to_last_nsu' => $this->to_last_nsu,
            'last_cstat' => $this->last_cstat,
            'correlation_id' => $this->correlation_id,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
