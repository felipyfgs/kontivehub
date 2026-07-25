<?php

namespace App\Services\Integra;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Models\ClientProcuracaoSnapshot;
use App\Models\ClientProcuracaoSync;
use Carbon\CarbonImmutable;

/**
 * Projeta para leitura o estado operacional de uma procuração já sincronizada.
 *
 * Não persiste, não consulta Integra e não infere autorização de poderes avulsos.
 */
final class ClientProcuracaoValidityResolver
{
    /** @return array{status: string, valid_to: ?string, checked_at: ?string} */
    public function resolve(
        ?ClientProcuracaoSync $sync,
        ?ClientProcuracaoSnapshot $snapshot = null,
        ?CarbonImmutable $now = null,
    ): array {
        $source = $sync ?? $snapshot;
        if ($source === null) {
            return $this->projection(ClientProcuracaoSyncStatus::Unverified, null, null);
        }

        $now ??= CarbonImmutable::now();
        $status = $source->status;
        $validTo = $source->valid_to;

        if ($status === ClientProcuracaoSyncStatus::Authorized && $validTo !== null) {
            if ($validTo->lessThanOrEqualTo($now)) {
                return $this->projection(ClientProcuracaoSyncStatus::Expired, $validTo, $source->last_verified_at);
            }

            if ($validTo->lessThanOrEqualTo($now->addDays(30))) {
                return $this->projection('expiring', $validTo, $source->last_verified_at);
            }
        }

        return $this->projection($status, $validTo, $source->last_verified_at);
    }

    /**
     * @param  ClientProcuracaoSyncStatus|'expiring'  $status
     * @return array{status: string, valid_to: ?string, checked_at: ?string}
     */
    private function projection(ClientProcuracaoSyncStatus|string $status, mixed $validTo, mixed $checkedAt): array
    {
        return [
            'status' => $status instanceof ClientProcuracaoSyncStatus ? $status->value : $status,
            'valid_to' => $validTo?->toIso8601String(),
            'checked_at' => $checkedAt?->toIso8601String(),
        ];
    }
}
