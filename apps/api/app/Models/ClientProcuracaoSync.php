<?php

namespace App\Models;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Models\Concerns\BelongsToOffice;
use Database\Factories\ClientProcuracaoSyncFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado sincronizado de procuração do cliente (evidência oficial).
 * Sem override manual — apenas sync oficial.
 */
#[Fillable([
    'office_id',
    'client_id',
    'status',
    'valid_from',
    'valid_to',
    'last_verified_at',
    'evidence_ref',
    'evidence_sha256',
    'powers_summary',
    'last_check_result',
    'last_sync_error_code',
    'source',
])]
class ClientProcuracaoSync extends Model
{
    /** @use HasFactory<ClientProcuracaoSyncFactory> */
    use BelongsToOffice, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ClientProcuracaoSyncStatus::class,
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'last_verified_at' => 'immutable_datetime',
            'powers_summary' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isAuthorized(): bool
    {
        return $this->status->isUsable();
    }

    /**
     * Payload sanitizado para API tenant (sem evidence bruta além de ref/sha).
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'status' => $this->status->value,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_to' => $this->valid_to?->toIso8601String(),
            'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            'evidence_sha256' => $this->evidence_sha256,
            'powers_summary' => $this->powers_summary,
            'last_check_result' => $this->last_check_result,
            'source' => $this->source,
            'is_authorized' => $this->isAuthorized(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected static function newFactory(): ClientProcuracaoSyncFactory
    {
        return ClientProcuracaoSyncFactory::new();
    }
}
