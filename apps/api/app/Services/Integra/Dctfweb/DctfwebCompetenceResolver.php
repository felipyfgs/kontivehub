<?php

namespace App\Services\Integra\Dctfweb;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;
use App\Models\Client;
use App\Models\FiscalCategory;
use App\Models\FiscalCompetence;
use App\Models\Office;
use InvalidArgumentException;

/** Resolve ou cria competência mensal (YYYY-MM) para DCTFWeb/MIT. */
final class DctfwebCompetenceResolver
{
    public function resolve(
        Office $office,
        Client $client,
        string $periodKey,
        string $categoryCode = DctfwebCodes::CATEGORY_DCTFWEB,
    ): FiscalCompetence {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new InvalidArgumentException('Cliente não pertence ao escritório ativo.');
        }

        $periodKey = $this->normalizePeriodKey($periodKey);
        [$year, $month] = $this->parsePeriod($periodKey);

        $category = FiscalCategory::query()
            ->where('code', strtoupper($categoryCode))
            ->first();

        $existing = FiscalCompetence::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('period_key', $periodKey)
            ->when(
                $category !== null,
                fn ($q) => $q->where('fiscal_category_id', $category->id),
                fn ($q) => $q->whereNull('fiscal_category_id'),
            )
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return FiscalCompetence::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'fiscal_category_id' => $category?->id,
            'period_key' => $periodKey,
            'period_year' => $year,
            'period_month' => $month,
            'situation' => FiscalSituation::Unknown,
            'coverage' => $categoryCode === DctfwebCodes::CATEGORY_MIT
                ? FiscalCoverage::Partial
                : FiscalCoverage::Full,
        ]);
    }

    public function normalizePeriodKey(string $periodKey): string
    {
        $periodKey = trim($periodKey);
        // Aceita YYYY-MM ou YYYYMM
        if (preg_match('/^\d{6}$/', $periodKey) === 1) {
            return substr($periodKey, 0, 4).'-'.substr($periodKey, 4, 2);
        }
        if (preg_match('/^\d{4}-\d{2}$/', $periodKey) !== 1) {
            throw new InvalidArgumentException("Competência inválida: {$periodKey}. Use YYYY-MM.");
        }

        return $periodKey;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function parsePeriod(string $periodKey): array
    {
        $parts = explode('-', $periodKey);
        $year = (int) $parts[0];
        $month = (int) $parts[1];
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            throw new InvalidArgumentException("Competência fora do intervalo: {$periodKey}");
        }

        return [$year, $month];
    }
}
