<?php

namespace App\Services\FiscalMonitoring;

use App\Enums\FiscalCoverage;

/**
 * Agrega dimensões sem promover cobertura parcial/desconhecida a FULL e sem
 * transformar uma dimensão UNSUPPORTED em indisponibilidade do conjunto todo.
 */
final class FiscalCoverageAggregator
{
    /** @param iterable<FiscalCoverage|string|null> $coverages */
    public function aggregate(iterable $coverages): FiscalCoverage
    {
        $values = [];
        foreach ($coverages as $coverage) {
            $value = $coverage instanceof FiscalCoverage
                ? $coverage
                : (is_string($coverage) ? FiscalCoverage::tryFrom(strtoupper($coverage)) : null);
            if ($value !== null) {
                $values[$value->value] = $value;
            }
        }

        if ($values === []) {
            return FiscalCoverage::Unknown;
        }
        if (count($values) === 1) {
            return array_values($values)[0];
        }
        if (isset($values[FiscalCoverage::Partial->value])) {
            return FiscalCoverage::Partial;
        }

        $hasKnownCoverage = isset($values[FiscalCoverage::Full->value]);
        $hasGap = isset($values[FiscalCoverage::Unsupported->value])
            || isset($values[FiscalCoverage::Unknown->value]);
        if ($hasKnownCoverage && $hasGap) {
            return FiscalCoverage::Partial;
        }
        if ($hasKnownCoverage) {
            return FiscalCoverage::Full;
        }
        if (isset($values[FiscalCoverage::Unknown->value])) {
            return FiscalCoverage::Unknown;
        }
        if (isset($values[FiscalCoverage::Unsupported->value])) {
            return FiscalCoverage::Unsupported;
        }

        return FiscalCoverage::NotApplicable;
    }
}
