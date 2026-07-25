<?php

namespace App\Services\Fiscal\Declarations;

use App\Models\TaxDeadlineCalendarVersion;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationVersion;
use Illuminate\Support\Collection;

/**
 * Catálogo versionado de obrigações, regimes, prazos, timezone, fontes e ops (11.1).
 */
final class TaxObligationCatalogService
{
    /**
     * @return Collection<int, TaxObligationDefinition>
     */
    public function listDefinitions(bool $activeOnly = true): Collection
    {
        $q = TaxObligationDefinition::query()
            ->with(['currentVersion.regimeRules'])
            ->orderBy('sort_order')
            ->orderBy('code');

        if ($activeOnly) {
            $q->where('is_active', true);
        }

        return $q->get();
    }

    public function findByCode(string $code): ?TaxObligationDefinition
    {
        return TaxObligationDefinition::query()
            ->where('code', strtoupper($code))
            ->first();
    }

    public function currentVersion(TaxObligationDefinition $definition): ?TaxObligationVersion
    {
        return TaxObligationVersion::query()
            ->where('obligation_definition_id', $definition->id)
            ->where('is_current', true)
            ->orderByDesc('version')
            ->first();
    }

    public function currentCalendar(string $code = 'RFB_NATIONAL'): ?TaxDeadlineCalendarVersion
    {
        return TaxDeadlineCalendarVersion::query()
            ->where('code', $code)
            ->where('is_current', true)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return Collection<int, TaxDeadlineCalendarVersion>
     */
    public function listCalendars(string $code = 'RFB_NATIONAL'): Collection
    {
        return TaxDeadlineCalendarVersion::query()
            ->where('code', $code)
            ->orderByDesc('version')
            ->get();
    }

    /**
     * Visão pública do catálogo (definições + versão corrente + regimes).
     *
     * @return list<array<string, mixed>>
     */
    public function catalogPayload(): array
    {
        return $this->listDefinitions(true)->map(function (TaxObligationDefinition $def) {
            $version = $def->currentVersion;
            $regimes = $version?->regimeRules?->map->toPublicArray()->values()->all() ?? [];

            return array_merge($def->toPublicArray(), [
                'current_version' => $version?->toPublicArray(),
                'regime_rules' => $regimes,
            ]);
        })->values()->all();
    }
}
