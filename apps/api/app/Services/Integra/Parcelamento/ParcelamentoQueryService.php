<?php

namespace App\Services\Integra\Parcelamento;

use App\Models\TaxGuide;
use App\Models\TaxInstallmentOrder;
use App\Models\TaxInstallmentParcel;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Leitura tenant-scoped de pedidos/parcelas/guias de parcelamento. */
final class ParcelamentoQueryService
{
    /**
     * @return LengthAwarePaginator<int, TaxInstallmentOrder>
     */
    public function paginateOrders(
        Tenant $tenant,
        int $perPage = 50,
        ?int $clientId = null,
        ?string $modality = null,
    ): LengthAwarePaginator {
        $q = TaxInstallmentOrder::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($modality !== null && $modality !== '') {
            $q->where('modality', strtoupper($modality));
        }

        return $q->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, TaxInstallmentParcel>
     */
    public function paginateParcels(
        Tenant $tenant,
        int $perPage = 50,
        ?int $clientId = null,
        ?int $orderId = null,
        ?string $modality = null,
    ): LengthAwarePaginator {
        $q = TaxInstallmentParcel::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($orderId !== null) {
            $q->where('order_id', $orderId);
        }
        if ($modality !== null && $modality !== '') {
            $q->where('modality', strtoupper($modality));
        }

        return $q->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, TaxGuide>
     */
    public function paginateGuides(
        Tenant $tenant,
        int $perPage = 50,
        ?int $clientId = null,
    ): LengthAwarePaginator {
        $q = TaxGuide::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('system_code', ParcelamentoServiceCatalog::SOLUTION)
            ->orderByDesc('id');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }

        return $q->paginate($perPage);
    }

    public function findOrder(Tenant $tenant, int $id): ?TaxInstallmentOrder
    {
        return TaxInstallmentOrder::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($id)
            ->first();
    }

    /** @return list<array<string, mixed>> */
    public function modalities(): array
    {
        return ParcelamentoServiceCatalog::modalities();
    }
}
