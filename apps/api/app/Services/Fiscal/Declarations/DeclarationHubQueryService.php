<?php

namespace App\Services\Fiscal\Declarations;

use App\Models\Office;
use App\Models\TaxDeliveryEvidence;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * API agregada da central de declarações com filtros e deep-links (11.5).
 */
final class DeclarationHubQueryService
{
    /**
     * @param  array{
     *   client_id?: int|null,
     *   obligation_code?: string|null,
     *   module_key?: string|null,
     *   period_key?: string|null,
     *   period_year?: int|null,
     *   period_month?: int|null,
     *   applicability?: string|null,
     *   situation?: string|null,
     *   delivery_status?: string|null,
     *   is_open?: bool|null,
     *   competence_id?: int|null,
     *   per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, TaxObligationProjection>
     */
    public function list(Office $office, array $filters = []): LengthAwarePaginator
    {
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 50)));

        $q = TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->with([
                'obligation',
                'obligationVersion',
                'calendarVersion',
                'conclusiveEvidence',
            ])
            ->where('office_id', $office->id)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderBy('obligation_definition_id');

        if (! empty($filters['client_id'])) {
            $q->where('client_id', (int) $filters['client_id']);
        }
        if (! empty($filters['period_key'])) {
            $q->where('period_key', (string) $filters['period_key']);
        }
        if (! empty($filters['period_year'])) {
            $q->where('period_year', (int) $filters['period_year']);
        }
        if (array_key_exists('period_month', $filters) && $filters['period_month'] !== null && $filters['period_month'] !== '') {
            $q->where('period_month', (int) $filters['period_month']);
        }
        if (! empty($filters['applicability'])) {
            $q->where('applicability', strtoupper((string) $filters['applicability']));
        }
        if (! empty($filters['situation'])) {
            $q->where('situation', strtoupper((string) $filters['situation']));
        }
        if (! empty($filters['delivery_status'])) {
            $q->where('delivery_status', strtoupper((string) $filters['delivery_status']));
        }
        if (array_key_exists('is_open', $filters) && $filters['is_open'] !== null) {
            $q->where('is_open', (bool) $filters['is_open']);
        }
        if (! empty($filters['competence_id'])) {
            $q->where('competence_id', (int) $filters['competence_id']);
        }
        if (! empty($filters['obligation_code'])) {
            $code = strtoupper((string) $filters['obligation_code']);
            $q->whereHas('obligation', fn ($qq) => $qq->where('code', $code));
        }
        if (! empty($filters['module_key'])) {
            $module = (string) $filters['module_key'];
            $q->whereHas('obligation', fn ($qq) => $qq->where('module_key', $module));
        }

        return $q->paginate($perPage);
    }

    public function find(Office $office, int $id): ?TaxObligationProjection
    {
        return TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->with([
                'obligation',
                'obligationVersion.regimeRules',
                'calendarVersion',
                'conclusiveEvidence',
                'evidences',
                'client',
            ])
            ->where('office_id', $office->id)
            ->whereKey($id)
            ->first();
    }

    public function findEvidence(Office $office, int $projectionId, int $evidenceId): ?TaxDeliveryEvidence
    {
        return TaxDeliveryEvidence::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('projection_id', $projectionId)
            ->whereKey($evidenceId)
            ->first();
    }

    /**
     * Resumo por obrigação (para cabeçalho da central do cliente).
     *
     * @return list<array<string, mixed>>
     */
    public function summaryByObligation(Office $office, ?int $clientId = null, ?string $periodKey = null): array
    {
        $q = TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->selectRaw('obligation_definition_id, applicability, delivery_status, COUNT(*) as total')
            ->where('office_id', $office->id)
            ->groupBy('obligation_definition_id', 'applicability', 'delivery_status');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($periodKey !== null && $periodKey !== '') {
            $q->where('period_key', $periodKey);
        }

        $rows = $q->get();
        $defs = TaxObligationDefinition::query()
            ->whereIn('id', $rows->pluck('obligation_definition_id')->unique()->all())
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($defs) {
            $def = $defs->get($row->obligation_definition_id);

            return [
                'obligation_definition_id' => (int) $row->obligation_definition_id,
                'obligation_code' => $def?->code,
                'obligation_name' => $def?->name,
                'module_key' => $def?->module_key,
                'applicability' => $row->applicability,
                'delivery_status' => $row->delivery_status,
                'total' => (int) $row->total,
            ];
        })->values()->all();
    }
}
