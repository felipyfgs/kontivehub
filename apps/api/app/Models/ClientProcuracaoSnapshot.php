<?php

namespace App\Models;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\SerproEnvironment;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'environment',
    'status',
    'valid_from',
    'valid_to',
    'last_verified_at',
    'evidence_ref',
    'power_codes',
    'last_check_result',
    'metadata',
])]
class ClientProcuracaoSnapshot extends Model
{
    use BelongsToOffice;

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

    public function isUsableForRequiredPower(): bool
    {
        if (! $this->status->isUsable()) {
            return false;
        }

        if ($this->valid_to !== null && $this->valid_to->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Client list projection (Procuração column).
     *
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
}
