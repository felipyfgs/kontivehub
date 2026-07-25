<?php

namespace App\Services\Esocial;

use App\DTO\Esocial\FgtsCompetenceProjection;
use App\Enums\EsocialEventCode;
use App\Enums\FgtsIndependentState;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalSituation;
use App\Models\EsocialEventEvidence;
use Carbon\CarbonImmutable;

/**
 * Projeta fechamento, totalização, guia e pagamento como estados INDEPENDENTES.
 * Guia/pagamento do portal FGTS Digital = UNSUPPORTED (sem API pública).
 */
final class FgtsIndependentStateProjector
{
    /**
     * @param  list<EsocialEventEvidence>  $evidences
     * @param  list<string>|null  $limitations
     */
    public function project(
        string $competencePeriodKey,
        array $evidences,
        ?CarbonImmutable $now = null,
        ?int $establishmentId = null,
        ?array $limitations = null,
        bool $sourceUnsupported = false,
    ): FgtsCompetenceProjection {
        $now ??= CarbonImmutable::now();
        $limitations ??= $this->defaultLimitations();

        if ($sourceUnsupported) {
            return new FgtsCompetenceProjection(
                competencePeriodKey: $competencePeriodKey,
                closureStatus: FgtsIndependentState::Unknown,
                totalizationStatus: FgtsIndependentState::Unknown,
                guideStatus: FgtsIndependentState::Unsupported,
                paymentStatus: FgtsIndependentState::Unsupported,
                coverage: FiscalCoverage::Unsupported,
                situation: FiscalSituation::Unsupported,
                findings: [[
                    'code' => 'COVERAGE_UNSUPPORTED',
                    'severity' => FiscalFindingSeverity::Info->value,
                    'title' => 'Fonte eSocial não suportada',
                    'detail' => 'Não há integração M2M oficial disponível; consulta manual sem scraping.',
                    'situation' => FiscalSituation::Unsupported->value,
                    'creates_pending' => false,
                ]],
                limitations: $limitations,
                normalized: [
                    'source' => 'esocial',
                    'partial' => true,
                    'declares_fgts_digital_debt' => false,
                    'guide_consulted' => false,
                    'payment_consulted' => false,
                ],
                establishmentId: $establishmentId,
            );
        }

        $hasClosure = false;
        $hasTotalizer = false;
        $closureObservedAt = null;
        $totalizerObservedAt = null;
        $eventCodes = [];

        foreach ($evidences as $ev) {
            $code = $ev->event_code instanceof EsocialEventCode
                ? $ev->event_code
                : EsocialEventCode::tryFromOfficial((string) $ev->event_code);
            if ($code === null) {
                continue;
            }
            $eventCodes[] = $code->value;
            if ($code->isClosure()) {
                $hasClosure = true;
                $at = $ev->occurred_at ?? $ev->observed_at;
                if ($at !== null && ($closureObservedAt === null || $at->lt($closureObservedAt))) {
                    $closureObservedAt = $at instanceof CarbonImmutable
                        ? $at
                        : CarbonImmutable::parse((string) $at);
                }
            }
            if ($code->isTotalizer()) {
                $hasTotalizer = true;
                $at = $ev->occurred_at ?? $ev->observed_at;
                if ($at !== null && ($totalizerObservedAt === null || $at->lt($totalizerObservedAt))) {
                    $totalizerObservedAt = $at instanceof CarbonImmutable
                        ? $at
                        : CarbonImmutable::parse((string) $at);
                }
            }
        }

        $windowHours = max(1, (int) config('fgts_esocial.totalizer_absence_window_hours', 72));

        // Fechamento e totalização são independentes entre si e de guia/pagamento.
        $closureStatus = $hasClosure
            ? FgtsIndependentState::Confirmed
            : FgtsIndependentState::Unknown;

        $totalizationStatus = FgtsIndependentState::Unknown;
        $totalizerDueBy = null;
        $findings = [];

        if ($hasTotalizer) {
            $totalizationStatus = FgtsIndependentState::Present;
        } elseif ($hasClosure && $closureObservedAt !== null) {
            $totalizerDueBy = $closureObservedAt->addHours($windowHours);
            if ($now->greaterThan($totalizerDueBy)) {
                $totalizationStatus = FgtsIndependentState::Absent;
                $findings[] = [
                    'code' => 'ESOCIAL_TOTALIZER_MISSING_AFTER_CLOSURE',
                    'severity' => FiscalFindingSeverity::Medium->value,
                    'title' => 'Totalizador eSocial ausente após fechamento',
                    'detail' => sprintf(
                        'Fechamento S-1299 confirmado para %s sem S-5003/S-5013 após janela de %dh. Revisão operacional — não declara débito do portal FGTS Digital.',
                        $competencePeriodKey,
                        $windowHours,
                    ),
                    'situation' => FiscalSituation::Attention->value,
                    'creates_pending' => true,
                ];
            } else {
                // Ainda na janela: não declarar ausência definitiva.
                $totalizationStatus = FgtsIndependentState::Unknown;
            }
        }

        // Guia e pagamento: sempre UNSUPPORTED (sem API FGTS Digital).
        $guideStatus = FgtsIndependentState::Unsupported;
        $paymentStatus = FgtsIndependentState::Unsupported;

        $situation = $this->deriveSituation(
            $hasClosure,
            $hasTotalizer,
            $totalizationStatus,
            $findings !== [],
        );

        $normalized = [
            'source' => 'esocial',
            'partial' => true,
            'coverage_label' => (string) config('fgts_esocial.coverage_label', 'FGTS (parcial eSocial)'),
            'event_codes' => array_values(array_unique($eventCodes)),
            'closure_status' => $closureStatus->value,
            'totalization_status' => $totalizationStatus->value,
            'guide_status' => $guideStatus->value,
            'payment_status' => $paymentStatus->value,
            'guide_consulted' => false,
            'payment_consulted' => false,
            'declares_fgts_digital_debt' => false,
            'closure_observed_at' => $closureObservedAt?->toIso8601String(),
            'totalizer_observed_at' => $totalizerObservedAt?->toIso8601String(),
            'totalizer_due_by' => $totalizerDueBy?->toIso8601String(),
            'limitations' => $limitations,
        ];

        return new FgtsCompetenceProjection(
            competencePeriodKey: $competencePeriodKey,
            closureStatus: $closureStatus,
            totalizationStatus: $totalizationStatus,
            guideStatus: $guideStatus,
            paymentStatus: $paymentStatus,
            coverage: FiscalCoverage::Partial,
            situation: $situation,
            findings: $findings,
            limitations: $limitations,
            normalized: $normalized,
            establishmentId: $establishmentId,
        );
    }

    /**
     * @param  list<array{code:string,severity?:string,title:string,detail?:string,situation?:string,creates_pending?:bool}>  $extraFindings
     */
    public function withExtraFindings(FgtsCompetenceProjection $base, array $extraFindings): FgtsCompetenceProjection
    {
        $findings = array_merge($base->findings, $extraFindings);
        $situation = $base->situation;
        foreach ($extraFindings as $f) {
            if (($f['situation'] ?? null) === FiscalSituation::Attention->value) {
                $situation = FiscalSituation::Attention;
            }
        }

        return new FgtsCompetenceProjection(
            competencePeriodKey: $base->competencePeriodKey,
            closureStatus: $base->closureStatus,
            totalizationStatus: $base->totalizationStatus,
            guideStatus: $base->guideStatus,
            paymentStatus: $base->paymentStatus,
            coverage: $base->coverage,
            situation: $situation,
            findings: $findings,
            limitations: $base->limitations,
            normalized: $base->normalized,
            establishmentId: $base->establishmentId,
        );
    }

    /**
     * @return list<string>
     */
    public function defaultLimitations(): array
    {
        /** @var list<string>|mixed $raw */
        $raw = config('fgts_esocial.limitations', []);

        return is_array($raw) ? array_values(array_map('strval', $raw)) : [];
    }

    /**
     * @param  list<array{code:string}>  $findingsIgnored
     */
    private function deriveSituation(
        bool $hasClosure,
        bool $hasTotalizer,
        FgtsIndependentState $totalizationStatus,
        bool $hasAttentionFindings,
    ): FiscalSituation {
        if ($hasAttentionFindings || $totalizationStatus === FgtsIndependentState::Absent) {
            return FiscalSituation::Attention;
        }

        if ($hasClosure || $hasTotalizer) {
            // Parcial com evidência eSocial — nunca UP_TO_DATE (guia/pagamento não consultados).
            return FiscalSituation::Attention;
        }

        return FiscalSituation::Unknown;
    }
}
