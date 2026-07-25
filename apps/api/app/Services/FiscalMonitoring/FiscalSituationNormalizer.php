<?php

namespace App\Services\FiscalMonitoring;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;
use InvalidArgumentException;

/**
 * Normalização honesta: preserva UNKNOWN/UNSUPPORTED/NOT_APPLICABLE
 * e proíbe inferência automática de UP_TO_DATE sem evidência + cobertura FULL.
 */
final class FiscalSituationNormalizer
{
    /**
     * @param  array<string, mixed>|null  $normalized
     * @return array{situation: FiscalSituation, coverage: FiscalCoverage, normalized: array<string, mixed>}
     */
    public function normalize(
        FiscalSituation $claimed,
        FiscalCoverage $coverage,
        bool $hasEvidence,
        ?array $normalized = null,
    ): array {
        $situation = $this->guardSituation($claimed, $coverage, $hasEvidence);
        $payload = $normalized ?? [];
        $payload['situation'] = $situation->value;
        $payload['coverage'] = $coverage->value;
        $payload['has_evidence'] = $hasEvidence;

        // Nunca promover silenciosamente a em dia no payload normalizado.
        if (
            isset($payload['inferred_up_to_date'])
            && $payload['inferred_up_to_date'] === true
            && $situation !== FiscalSituation::UpToDate
        ) {
            unset($payload['inferred_up_to_date']);
            $payload['inference_rejected'] = 'UP_TO_DATE_WITHOUT_EVIDENCE';
        }

        return [
            'situation' => $situation,
            'coverage' => $coverage,
            'normalized' => $payload,
        ];
    }

    public function guardSituation(
        FiscalSituation $claimed,
        FiscalCoverage $coverage,
        bool $hasEvidence,
    ): FiscalSituation {
        // Cobertura/fonte sem suporte prevalece.
        if ($coverage === FiscalCoverage::Unsupported) {
            return FiscalSituation::Unsupported;
        }

        if ($coverage === FiscalCoverage::NotApplicable) {
            return FiscalSituation::NotApplicable;
        }

        // UP_TO_DATE exige evidência positiva e cobertura plena.
        if ($claimed === FiscalSituation::UpToDate) {
            if (! $hasEvidence) {
                return FiscalSituation::Unknown;
            }
            if (! $coverage->allowsUpToDateClaim()) {
                // Parcial com evidência → no máximo ATTENTION, nunca inventar "em dia".
                return FiscalSituation::Attention;
            }

            return FiscalSituation::UpToDate;
        }

        // PENDING/ATTENTION/NOT_APPLICABLE com claim sem evidência → UNKNOWN (não inventar pendência fiscal).
        if ($claimed->requiresPositiveEvidence() && ! $hasEvidence) {
            return match ($claimed) {
                FiscalSituation::NotApplicable => FiscalSituation::Unknown,
                default => FiscalSituation::Unknown,
            };
        }

        return $claimed;
    }

    /**
     * Resolve situação a partir de string de adapter (fail-closed em valor inválido).
     */
    public function parseSituation(?string $value): FiscalSituation
    {
        if ($value === null || $value === '') {
            return FiscalSituation::Unknown;
        }

        $situation = FiscalSituation::tryFrom(strtoupper($value));
        if ($situation === null) {
            throw new InvalidArgumentException("Situação fiscal desconhecida: {$value}");
        }

        return $situation;
    }

    public function parseCoverage(?string $value): FiscalCoverage
    {
        if ($value === null || $value === '') {
            return FiscalCoverage::Unknown;
        }

        return FiscalCoverage::tryFrom(strtoupper($value)) ?? FiscalCoverage::Unknown;
    }
}
