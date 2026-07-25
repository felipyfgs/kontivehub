<?php

namespace App\Models;

use App\Enums\CaptureChannel;
use App\Enums\SyncCursorStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id', 'office_fiscal_identity_id', 'interested_root_cnpj', 'query_cnpj',
    'environment', 'channel', 'last_nsu', 'max_nsu_seen', 'status',
    'last_cstat', 'last_xmotivo', 'consecutive_decode_failures', 'attempts',
    'activated_at', 'next_sync_at', 'last_success_at', 'last_heartbeat_at',
    'locked_at', 'lock_owner', 'external_consumer_status', 'last_error',
])]
class OfficeDistributionCursor extends Model
{
    use BelongsToOffice;
    use HasFactory;

    protected function casts(): array
    {
        return [
            'channel' => CaptureChannel::class,
            'status' => SyncCursorStatus::class,
            'last_nsu' => 'integer',
            'max_nsu_seen' => 'integer',
            'consecutive_decode_failures' => 'integer',
            'attempts' => 'integer',
            'activated_at' => 'immutable_datetime',
            'next_sync_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_heartbeat_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
        ];
    }

    public function fiscalIdentity(): BelongsTo
    {
        return $this->belongsTo(OfficeFiscalIdentity::class, 'office_fiscal_identity_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(OfficeDistributionRun::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $circuitOpen = $this->last_cstat === '656'
            || $this->status === SyncCursorStatus::Blocked
            || (
                $this->next_sync_at !== null
                && $this->last_cstat === '656'
            );

        return [
            'id' => $this->id,
            'interested_root_cnpj' => $this->interested_root_cnpj,
            'query_cnpj' => $this->query_cnpj,
            'environment' => $this->environment,
            'channel' => $this->channel instanceof CaptureChannel
                ? $this->channel->value
                : (string) $this->channel,
            'last_nsu' => $this->last_nsu,
            'max_nsu_seen' => $this->max_nsu_seen,
            'status' => $this->status instanceof SyncCursorStatus
                ? $this->status->value
                : (string) $this->status,
            'last_cstat' => $this->last_cstat,
            'last_xmotivo' => $this->last_xmotivo,
            'consecutive_decode_failures' => $this->consecutive_decode_failures,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'next_sync_at' => $this->next_sync_at?->toIso8601String(),
            'last_success_at' => $this->last_success_at?->toIso8601String(),
            'last_heartbeat_at' => $this->last_heartbeat_at?->toIso8601String(),
            'external_consumer_status' => $this->external_consumer_status,
            'circuit_open' => $circuitOpen,
            // Nunca expor last_error bruto se contiver payload — só cStat/xmotivo sanitizados.
        ];
    }
}
