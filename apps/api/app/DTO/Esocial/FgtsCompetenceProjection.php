<?php

namespace App\DTO\Esocial;

use App\Enums\FgtsIndependentState;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;

/**
 * Projeção de estados independentes FGTS/eSocial para uma competência.
 * Guia e pagamento do portal NUNCA são CONFIRMADOS/PRESENT sem fonte oficial.
 */
final readonly class FgtsCompetenceProjection
{
    /**
     * @param  list<array{code:string,severity?:string,title:string,detail?:string,situation?:string,creates_pending?:bool}>  $findings
     * @param  list<string>  $limitations
     * @param  array<string, mixed>  $normalized
     */
    public function __construct(
        public string $competencePeriodKey,
        public FgtsIndependentState $closureStatus,
        public FgtsIndependentState $totalizationStatus,
        public FgtsIndependentState $guideStatus,
        public FgtsIndependentState $paymentStatus,
        public FiscalCoverage $coverage,
        public FiscalSituation $situation,
        public array $findings = [],
        public array $limitations = [],
        public array $normalized = [],
        public ?int $establishmentId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'competence_period_key' => $this->competencePeriodKey,
            'establishment_id' => $this->establishmentId,
            'closure_status' => $this->closureStatus->value,
            'totalization_status' => $this->totalizationStatus->value,
            'guide_status' => $this->guideStatus->value,
            'payment_status' => $this->paymentStatus->value,
            'coverage' => $this->coverage->value,
            'situation' => $this->situation->value,
            'limitations' => $this->limitations,
            'findings' => $this->findings,
            'normalized' => $this->normalized,
        ];
    }
}
