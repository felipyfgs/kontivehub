<?php

namespace App\Services\Integra\Parcelamento;

use App\Models\Office;
use App\Models\TaxGuide;
use App\Models\TaxInstallmentOrder;
use App\Models\TaxInstallmentParcel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Leitura tenant-scoped de pedidos/parcelas/guias de parcelamento. */
final class ParcelamentoQueryService
{
    /**
     * @return LengthAwarePaginator<int, TaxInstallmentOrder>
     */
    public function paginateOrders(
        Office $office,
        int $perPage = 50,
        ?int $clientId = null,
        ?string $modality = null,
    ): LengthAwarePaginator {
        $q = TaxInstallmentOrder::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
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
        Office $office,
        int $perPage = 50,
        ?int $clientId = null,
        ?int $orderId = null,
        ?string $modality = null,
    ): LengthAwarePaginator {
        $q = TaxInstallmentParcel::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
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
        Office $office,
        int $perPage = 50,
        ?int $clientId = null,
    ): LengthAwarePaginator {
        $q = TaxGuide::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('system_code', ParcelamentoServiceCatalog::SOLUTION)
            ->orderByDesc('id');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }

        return $q->paginate($perPage);
    }

    public function findOrder(Office $office, int $id): ?TaxInstallmentOrder
    {
        return TaxInstallmentOrder::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($id)
            ->first();
    }

    /** @return list<array<string, mixed>> */
    public function modalities(): array
    {
        return ParcelamentoServiceCatalog::modalities();
    }
}
