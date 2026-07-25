<?php

namespace App\Services\Sefaz;

use App\Enums\SyncCursorStatus;
use App\Models\OfficeDistributionCursor;
use Carbon\CarbonImmutable;

/**
 * Circuit breaker cStat 656 para stream autXML do escritório.
 * Suspende consultas da raiz/ambiente ≥ 1h desde a tentativa mais recente.
 */
final class AutXmlCircuitBreaker
{
    public function openForCursor(OfficeDistributionCursor $cursor, string $cStat = '656', ?string $xMotivo = null): void
    {
        $hours = max(1.0, (float) config('sefaz.autxml.circuit_breaker_hours', 1));
        $cursor->status = SyncCursorStatus::Blocked;
        $cursor->last_cstat = $cStat;
        if ($xMotivo !== null) {
            $cursor->last_xmotivo = mb_substr($xMotivo, 0, 255);
        }
        $cursor->last_error = 'Consumo indevido SEFAZ (cStat 656). Circuit breaker ≥'.$hours.'h.';
        $cursor->next_sync_at = CarbonImmutable::now()->addHours($hours);
        $cursor->save();
    }

    public function isOpen(OfficeDistributionCursor $cursor): bool
    {
        if ($cursor->status !== SyncCursorStatus::Blocked) {
            return false;
        }
        if ($cursor->last_cstat !== '656') {
            return $cursor->status === SyncCursorStatus::Blocked;
        }
        if ($cursor->next_sync_at === null) {
            return true;
        }

        return $cursor->next_sync_at->isFuture();
    }

    /**
     * Impede retry antecipado: se ainda no cooldown, retorna false.
     */
    public function mayQuery(OfficeDistributionCursor $cursor): bool
    {
        if (! $this->isOpen($cursor)) {
            return true;
        }

        return false;
    }
}
