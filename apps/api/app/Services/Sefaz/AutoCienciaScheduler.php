<?php

namespace App\Services\Sefaz;

use App\Jobs\AutoCienciaNfeJob;
use App\Models\NfeDocument;
use Illuminate\Support\Facades\Log;

/**
 * Enfileira ciência técnica (210210) para resumos sem procNFe.
 */
final class AutoCienciaScheduler
{
    public function isEnabled(): bool
    {
        return (bool) config('sefaz.auto_ciencia_enabled', false)
            || (bool) config('sefaz.manifest_enabled', false);
    }

    /**
     * @param  list<string>  $accessKeys
     * @return int jobs enfileirados
     */
    public function enqueueForKeys(int $tenantId, array $accessKeys, int $delayBaseSeconds = 0): int
    {
        if (! $this->isEnabled() || $accessKeys === []) {
            return 0;
        }

        $unique = array_values(array_unique(array_filter($accessKeys)));
        $max = max(1, (int) config('sefaz.auto_ciencia_max_per_page', 30));
        $step = max(1, (int) config('sefaz.auto_ciencia_delay_seconds', 3));
        $unique = array_slice($unique, 0, $max);

        $n = 0;
        foreach ($unique as $i => $accessKey) {
            if (! $this->needsCiencia($tenantId, $accessKey)) {
                continue;
            }

            $delay = $delayBaseSeconds + ($i * $step);
            $pending = AutoCienciaNfeJob::dispatch($tenantId, $accessKey);
            if ($delay > 0) {
                $pending->delay(now()->addSeconds($delay));
            }
            $n++;
        }

        if ($n > 0) {
            Log::info('sefaz.auto_ciencia.enqueued', [
                'tenant_id' => $tenantId,
                'count' => $n,
            ]);
        }

        return $n;
    }

    /**
     * Catch-up: resumos PENDING sem full no tenant (ou filtro opcional).
     *
     * @return int jobs enfileirados
     */
    public function enqueuePending(?int $tenantId = null, ?int $establishmentId = null, int $limit = 100): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $query = NfeDocument::query()
            ->where('is_summary', true)
            ->where(function ($q): void {
                $q->whereNull('manifestation_status')
                    ->orWhere('manifestation_status', 'PENDING_MANIFESTATION');
            })
            ->orderBy('id');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if ($establishmentId !== null) {
            $query->whereHas('document.interests', function ($q) use ($establishmentId): void {
                $q->where('establishment_id', $establishmentId);
            });
        }

        $rows = $query->limit($limit)->get(['tenant_id', 'access_key']);
        $byTenant = $rows->groupBy('tenant_id');
        $total = 0;
        foreach ($byTenant as $oid => $group) {
            $keys = $group->pluck('access_key')->all();
            $total += $this->enqueueForKeys((int) $oid, $keys);
        }

        return $total;
    }

    public function needsCiencia(int $tenantId, string $accessKey): bool
    {
        $hasFull = NfeDocument::query()
            ->where('tenant_id', $tenantId)
            ->where('access_key', $accessKey)
            ->where('is_summary', false)
            ->exists();

        if ($hasFull) {
            return false;
        }

        $summary = NfeDocument::query()
            ->where('tenant_id', $tenantId)
            ->where('access_key', $accessKey)
            ->where('is_summary', true)
            ->first();

        if ($summary === null) {
            return false;
        }

        $status = (string) ($summary->manifestation_status ?? '');

        return ! in_array($status, ['CIENCIA_REGISTRADA', 'CONFIRMADA', 'DESCONHECIDA', 'NAO_REALIZADA'], true);
    }
}
