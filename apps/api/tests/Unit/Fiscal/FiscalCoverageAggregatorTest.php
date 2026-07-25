<?php

namespace Tests\Unit\Fiscal;

use App\Enums\FgtsIndependentState;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;
use App\Services\Esocial\FgtsIndependentStateProjector;
use App\Services\FiscalMonitoring\FiscalCoverageAggregator;
use Tests\TestCase;

class FiscalCoverageAggregatorTest extends TestCase
{
    public function test_aggregate_preserves_partial_and_unsupported_dimensions_without_promoting_full(): void
    {
        $aggregate = app(FiscalCoverageAggregator::class);

        $this->assertSame(
            FiscalCoverage::Partial,
            $aggregate->aggregate([FiscalCoverage::Full, FiscalCoverage::Unsupported]),
        );
        $this->assertSame(
            FiscalCoverage::Partial,
            $aggregate->aggregate([FiscalCoverage::Full, FiscalCoverage::Unknown]),
        );
        $this->assertSame(
            FiscalCoverage::Unsupported,
            $aggregate->aggregate([FiscalCoverage::Unsupported]),
        );
        $this->assertSame(
            FiscalCoverage::Full,
            $aggregate->aggregate([FiscalCoverage::Full, FiscalCoverage::NotApplicable]),
        );
        $this->assertSame(FiscalCoverage::Unknown, $aggregate->aggregate([]));
    }

    public function test_fgts_keeps_supported_esocial_dimensions_separate_from_unsupported_guide_and_payment(): void
    {
        $projector = new FgtsIndependentStateProjector;
        $partial = $projector->project('2026-07', []);

        $this->assertSame(FiscalCoverage::Partial, $partial->coverage);
        $this->assertSame(FiscalSituation::Unknown, $partial->situation);
        $this->assertSame(FgtsIndependentState::Unsupported, $partial->guideStatus);
        $this->assertSame(FgtsIndependentState::Unsupported, $partial->paymentStatus);
        $this->assertFalse($partial->normalized['declares_fgts_digital_debt']);

        $unsupported = $projector->project('2026-07', [], sourceUnsupported: true);
        $this->assertSame(FiscalCoverage::Unsupported, $unsupported->coverage);
        $this->assertSame(FiscalSituation::Unsupported, $unsupported->situation);
        $this->assertSame(FgtsIndependentState::Unsupported, $unsupported->guideStatus);
        $this->assertSame(FgtsIndependentState::Unsupported, $unsupported->paymentStatus);
    }
}
