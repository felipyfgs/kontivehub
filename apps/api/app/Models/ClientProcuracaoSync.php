<?php

namespace App\Models;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\SerproEnvironment;
use App\Models\Concerns\BelongsToTenant;
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
    'tenant_id',
    'client_id',
    'environment',
    'status',
    'valid_from',
    'valid_to',
    'last_verified_at',
    'evidence_ref',
    'evidence_sha256',
    'power_codes',
    'last_check_result',
    'last_sync_error_code',
    'source',
    'metadata',
])]
class ClientProcuracaoSync extends Model
{
    /** @use HasFactory<ClientProcuracaoSyncFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'environment' => SerproEnvironment::class,
            'status' => ClientProcuracaoSyncStatus::class,
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
            'last_verified_at' => 'immutable_datetime',
            'power_codes' => 'array',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isAuthorized(): bool
    {
        return $this->status->isUsable()
            && ($this->valid_to === null || $this->valid_to->isFuture());
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientProjection(): array
    {
        $expiringSoon = $this->status === ClientProcuracaoSyncStatus::Authorized
            && $this->valid_to !== null
            && $this->valid_to->isFuture()
            && $this->valid_to->lessThanOrEqualTo(now()->addDays(30));

        return [
            'status' => $this->status->value,
            'display_status' => $expiringSoon ? 'expiring_soon' : $this->status->value,
            'label' => $expiringSoon ? 'Vence em breve' : match ($this->status) {
                ClientProcuracaoSyncStatus::Authorized => 'Autorizada',
                ClientProcuracaoSyncStatus::Missing => 'Não encontrada',
                ClientProcuracaoSyncStatus::Expired => 'Vencida',
                ClientProcuracaoSyncStatus::Unverified => 'Não verificada',
                ClientProcuracaoSyncStatus::Verifying => 'Verificando',
                ClientProcuracaoSyncStatus::Failed => 'Falha ao verificar',
            },
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_to' => $this->valid_to?->toIso8601String(),
            'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            'covered_modules' => $this->power_codes ?? [],
        ];
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
            'tenant_id' => $this->tenant_id,
            'client_id' => $this->client_id,
            'environment' => $this->environment->value,
            'status' => $this->status->value,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_to' => $this->valid_to?->toIso8601String(),
            'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            'evidence_sha256' => $this->evidence_sha256,
            'power_codes' => $this->power_codes,
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
