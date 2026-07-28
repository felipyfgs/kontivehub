<?php

namespace App\Http\Resources;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Models\ClientProcuracaoSync;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientProcuracaoSync */
final class ClientProcuracaoSyncResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientProcuracaoSync $sync */
        $sync = $this->resource;
        $expiringSoon = $sync->status === ClientProcuracaoSyncStatus::Authorized
            && $sync->valid_to !== null
            && $sync->valid_to->isFuture()
            && $sync->valid_to->lessThanOrEqualTo(now()->addDays(30));

        return [
            'status' => $sync->status->value,
            'display_status' => $expiringSoon ? 'expiring_soon' : $sync->status->value,
            'label' => $expiringSoon ? 'Vence em breve' : match ($sync->status) {
                ClientProcuracaoSyncStatus::Authorized => 'Autorizada',
                ClientProcuracaoSyncStatus::Missing => 'Não encontrada',
                ClientProcuracaoSyncStatus::Expired => 'Vencida',
                ClientProcuracaoSyncStatus::Unverified => 'Não verificada',
                ClientProcuracaoSyncStatus::Verifying => 'Verificando',
                ClientProcuracaoSyncStatus::Failed => 'Falha ao verificar',
            },
            'valid_from' => $sync->valid_from?->toIso8601String(),
            'valid_to' => $sync->valid_to?->toIso8601String(),
            'last_verified_at' => $sync->last_verified_at?->toIso8601String(),
            'covered_modules' => $sync->power_codes ?? [],
        ];
    }
}
