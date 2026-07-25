<?php

declare(strict_types=1);

namespace Tests\Unit\Esocial;

use App\Contracts\EsocialEventClient;
use App\Services\Esocial\DisabledEsocialEventClient;
use App\Services\Esocial\FgtsEsocialMonitoringService;
use App\Services\Esocial\HttpEsocialBxEventClient;
use Tests\TestCase;

class EsocialBxBindingManifestTest extends TestCase
{
    public function test_container_resolves_disabled_official_and_invalid_drivers_fail_closed(): void
    {
        config()->set('fgts_esocial.driver', 'disabled');
        $disabled = app(EsocialEventClient::class);
        $this->assertInstanceOf(DisabledEsocialEventClient::class, $disabled);
        $this->assertSame('ESOCIAL_SOURCE_UNAVAILABLE', $disabled->unavailableCode());

        config()->set('fgts_esocial.driver', 'official_bx');
        $this->assertInstanceOf(HttpEsocialBxEventClient::class, app(EsocialEventClient::class));

        config()->set('fgts_esocial.driver', 'invented');
        $invalid = app(EsocialEventClient::class);
        $this->assertInstanceOf(DisabledEsocialEventClient::class, $invalid);
        $this->assertSame('ESOCIAL_BX_DRIVER_INVALID', $invalid->unavailableCode());
        $this->assertStringNotContainsString('invented', $invalid->unavailableMessage());
    }

    public function test_coverage_manifest_distinguishes_accepted_automatic_and_context_events(): void
    {
        config()->set('fgts_esocial.driver', 'official_bx');
        config()->set('fgts_esocial.environment', 'restricted');
        $manifest = app(FgtsEsocialMonitoringService::class)->coverageManifest();

        $this->assertTrue($manifest['source_available']);
        $this->assertSame('ESOCIAL_BX_OFFICIAL', $manifest['source']);
        $this->assertSame('SOAP_1_1_MTLS', $manifest['transport']);
        $this->assertSame('restricted', $manifest['environment']);
        $this->assertSame(
            ['S-5003', 'S-5013', 'S-1299'],
            array_column($manifest['accepted_events'], 'code'),
        );
        $this->assertSame(
            ['S-1299', 'S-5013'],
            array_column($manifest['automatic_events'], 'code'),
        );
        $this->assertSame('S-5003', $manifest['context_required_events'][0]['code']);
        $this->assertSame(10, $manifest['official_limits']['daily_accesses_per_employer']);
        $this->assertSame(50, $manifest['official_limits']['max_ids_per_download']);
        $this->assertSame(60, $manifest['official_limits']['minimum_lag_minutes']);
        $this->assertSame(31, $manifest['official_limits']['max_query_interval_days']);
        $this->assertFalse($manifest['official_limits']['parallel_requests_allowed']);
        $this->assertStringStartsWith('https://www.gov.br/esocial/', $manifest['official_links']['manual']);
        $this->assertStringStartsWith('https://www.gov.br/esocial/', $manifest['official_links']['announcement']);
        $this->assertStringStartsWith('https://www.gov.br/esocial/', $manifest['official_links']['communication_package']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest['wsdl_sha256']['identifiers']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest['wsdl_sha256']['downloads']);
        $this->assertFalse($manifest['declares_fgts_digital_debt']);
        $this->assertFalse($manifest['portal_fallback']);
    }
}
