<?php

namespace App\Services\FiscalMonitoring;

use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalFinding;
use App\Models\FiscalPendingItem;
use App\Models\FiscalSnapshot;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Leituras tenant-scoped de snapshots, findings e pendências. */
final class FiscalQueryService
{
    /**
     * @return LengthAwarePaginator<int, FiscalSnapshot>
     */
    public function snapshots(
        Tenant $tenant,
        int $perPage = 50,
        ?int $clientId = null,
        ?bool $currentOnly = true,
    ): LengthAwarePaginator {
        $q = FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($currentOnly) {
            $q->where('is_current', true);
        }

        return $q->paginate($perPage);
    }

    public function snapshot(Tenant $tenant, int $id): ?FiscalSnapshot
    {
        return FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($id)
            ->first();
    }

    /**
     * @return LengthAwarePaginator<int, FiscalFinding>
     */
    public function findings(
        Tenant $tenant,
        int $perPage = 50,
        ?int $clientId = null,
        ?bool $activeOnly = true,
    ): LengthAwarePaginator {
        $q = FiscalFinding::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
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
        Tenant $tenant,
        int $perPage = 50,
        ?int $clientId = null,
        ?string $status = 'OPEN',
    ): LengthAwarePaginator {
        $q = FiscalPendingItem::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }

        return $q->paginate($perPage);
    }

    public function evidence(Tenant $tenant, int $id): ?FiscalEvidenceArtifact
    {
        return FiscalEvidenceArtifact::query()
            ->withoutGlobalScopes()
            ->operationallyEligible()
            ->where('tenant_id', $tenant->id)
            ->whereKey($id)
            ->first();
    }
}
