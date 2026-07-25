<?php

namespace Tests\Unit\Fiscal;

use App\Services\FiscalMonitoring\Surfaces\MonitoringCoverageService;
use Tests\TestCase;

class MonitoringCoverageServiceTest extends TestCase
{
    public function test_projects_every_monitoring_surface_without_exposing_serpro_coordinates(): void
    {
        $coverage = app(MonitoringCoverageService::class)->publicCoverage();

        $this->assertSame(15, $coverage['totals']['surfaces']);
        $this->assertSame(119, $coverage['totals']['catalog_operations']);
        // DCTFWeb(1) + MIT(3) + SITFIS trial (solicitar + emitir) = 6
        $this->assertSame(6, $coverage['totals']['trial_scenarios']);

        $dctfweb = collect($coverage['surfaces'])->firstWhere('surface_key', 'dctfweb');
        $this->assertIsArray($dctfweb);
        $this->assertSame(5, $dctfweb['operations_total']);
        $this->assertSame(1, $dctfweb['trial_scenarios']);
        $this->assertSame(
            collect($dctfweb['capabilities'])->flatMap(
                static fn (array $capability): array => array_column($capability['actions'], 'action_key'),
            )->values()->all(),
            array_column($dctfweb['operations'], 'action_key'),
        );
        $this->assertContains(
            'PDFByteArrayBase64',
            collect($dctfweb['operations'])->flatMap(
                static fn (array $operation): array => array_column($operation['output_fields'], 'name'),
            )->all(),
        );

        $mit = collect($coverage['surfaces'])->firstWhere('surface_key', 'mit');
        $this->assertIsArray($mit);
        $this->assertSame(3, $mit['trial_scenarios']);

        $fgts = collect($coverage['surfaces'])->firstWhere('surface_key', 'fgts');
        $this->assertIsArray($fgts);
        $this->assertSame(0, $fgts['operations_total']);
        $this->assertSame('eSocial', $fgts['channel_label']);

        $json = json_encode($coverage, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('operation_key', $json);
        $this->assertStringNotContainsString('idSistema', $json);
        $this->assertStringNotContainsString('idServico', $json);
        $this->assertStringNotContainsString('business_data', $json);
        $this->assertStringNotContainsString('handler', $json);
        $this->assertStringNotContainsString('run_codes', $json);
        $this->assertStringNotContainsString('required_proxy_powers', $json);
    }
}
