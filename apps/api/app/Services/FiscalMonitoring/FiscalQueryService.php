<?php

namespace App\Services\FiscalMonitoring;

use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalFinding;
use App\Models\FiscalPendingItem;
use App\Models\FiscalSnapshot;
use App\Models\Office;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Leituras tenant-scoped de snapshots, findings e pendências. */
final class FiscalQueryService
{
    /**
     * @return LengthAwarePaginator<int, FiscalSnapshot>
     */
    public function snapshots(
        Office $office,
        int $perPage = 50,
        ?int $clientId = null,
        ?bool $currentOnly = true,
    ): LengthAwarePaginator {
        $q = FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->orderByDesc('id');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($currentOnly) {
            $q->where('is_current', true);
        }

        return $q->paginate($perPage);
    }

    public function snapshot(Office $office, int $id): ?FiscalSnapshot
    {
        return FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($id)
            ->first();
    }

    /**
     * @return LengthAwarePaginator<int, FiscalFinding>
     */
    public function findings(
        Office $office,
        int $perPage = 50,
        ?int $clientId = null,
        ?bool $activeOnly = true,
    ): LengthAwarePaginator {
        $q = FiscalFinding::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->orderByDesc('id');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($activeOnly) {
            $q->where('is_active', true);
        }

        return $q->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, FiscalPendingItem>
     */
    public function pendingItems(
        Office $office,
        int $perPage = 50,
        ?int $clientId = null,
        ?string $status = 'OPEN',
    ): LengthAwarePaginator {
        $q = FiscalPendingItem::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->orderByDesc('id');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }

        return $q->paginate($perPage);
    }

    public function evidence(Office $office, int $id): ?FiscalEvidenceArtifact
    {
        return FiscalEvidenceArtifact::query()
            ->withoutGlobalScopes()
            ->operationallyEligible()
            ->where('office_id', $office->id)
            ->whereKey($id)
            ->first();
    }
}
