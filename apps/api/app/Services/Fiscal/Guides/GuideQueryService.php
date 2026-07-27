<?php

namespace App\Services\Fiscal\Guides;

use App\Models\TaxGuide;
use App\Models\TaxGuideVersion;
use App\Models\Tenant;
use App\Services\Fiscal\Guides\Exceptions\GuideException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class GuideQueryService
{
    public function paginate(
        Tenant $tenant,
        int $perPage = 50,
        ?int $clientId = null,
        ?string $paymentStatus = null,
        string $sort = '',
        string $direction = '',
    ): LengthAwarePaginator {
        $sortColumn = match ($sort) {
            'client_id' => 'client_id',
            'system_code' => 'system_code',
            'competence' => 'competence_period_key',
            'amount' => 'amount_cents',
            'due_at' => 'due_at',
            'payment_status' => 'payment_status',
            default => 'id',
        };
        $sortDirection = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $q = TaxGuide::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->with(['currentVersion' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy($sortColumn, $sortDirection);
        if ($sortColumn !== 'id') {
            $q->orderBy('id', $sortDirection);
        }

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($paymentStatus !== null && $paymentStatus !== '') {
            $q->where('payment_status', $paymentStatus);
        }

        return $q->paginate($perPage);
    }

    public function find(Tenant $tenant, int $guideId): TaxGuide
    {
        $guide = TaxGuide::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($guideId)
            ->with([
                'currentVersion' => fn ($q) => $q->withoutGlobalScopes(),
                'versions' => fn ($q) => $q->withoutGlobalScopes()->orderBy('version_number'),
                'paymentConfirmations' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->first();

        if ($guide === null) {
            throw GuideException::notFound();
        }

        return $guide;
    }

    public function findVersion(Tenant $tenant, int $versionId): TaxGuideVersion
    {
        $version = TaxGuideVersion::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($versionId)
            ->first();

        if ($version === null) {
            throw GuideException::notFound('Versão de guia não encontrada.');
        }

        return $version;
    }

    /**
     * Tenant cruzado: retorna null (não vaza existência).
     */
    public function findOrNull(Tenant $tenant, int $guideId): ?TaxGuide
    {
        return TaxGuide::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($guideId)
            ->first();
    }
}
