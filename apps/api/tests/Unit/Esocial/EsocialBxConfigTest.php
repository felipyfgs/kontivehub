<?php

declare(strict_types=1);

namespace Tests\Unit\Esocial;

use App\Exceptions\EsocialBxException;
use App\Services\Esocial\EsocialBxConfig;
use Tests\TestCase;

class EsocialBxConfigTest extends TestCase
{
    private EsocialBxConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = app(EsocialBxConfig::class);
    }

    public function test_defaults_are_restricted_and_fail_closed_with_official_metadata(): void
    {
        $this->assertSame('disabled', config('fgts_esocial.driver'));
        $this->assertSame('restricted', config('fgts_esocial.environment'));
        $this->assertFalse(config('fgts_esocial.production_egress_enabled'));
        $this->assertSame('America/Sao_Paulo', config('fgts_esocial.official_bx.timezone'));
        $this->assertSame(60, config('fgts_esocial.official_bx.minimum_lag_minutes'));
        $this->assertSame(31, config('fgts_esocial.official_bx.max_query_interval_days'));
        $this->assertSame(10, config('fgts_esocial.official_bx.daily_access_limit'));
        $this->assertSame(50, config('fgts_esocial.official_bx.batch_limit'));
        $this->assertStringStartsWith('https://www.gov.br/esocial/', config('fgts_esocial.official_bx.manual_url'));
        $this->assertSame([], $this->config->blockers());
    }

    public function test_production_requires_explicit_egress_gate(): void
    {
        config()->set('fgts_esocial.driver', 'official_bx');
        config()->set('fgts_esocial.environment', 'production');

        $this->assertContains(
            'ESOCIAL_BX_PRODUCTION_EGRESS_DISABLED',
            array_column($this->config->blockers(), 'code'),
        );

        config()->set('fgts_esocial.production_egress_enabled', true);
        $this->assertSame([], $this->config->blockers());
    }

    public function test_invalid_driver_timezone_limits_window_and_timeouts_fail_closed(): void
    {
        config()->set('fgts_esocial.driver', 'invented');
        config()->set('fgts_esocial.official_bx.timezone', 'Mars/Olympus');
        config()->set('fgts_esocial.official_bx.daily_access_limit', 11);
        config()->set('fgts_esocial.official_bx.minimum_lag_minutes', 59);
        config()->set('fgts_esocial.official_bx.blocked_days', [2, 3]);
        config()->set('fgts_esocial.official_bx.lock_seconds', 90);

        $codes = array_column($this->config->blockers(), 'code');
        $this->assertContains('ESOCIAL_BX_DRIVER_INVALID', $codes);
        $this->assertContains('ESOCIAL_BX_TIMEZONE_INVALID', $codes);
        $this->assertContains('ESOCIAL_BX_LIMITS_INVALID', $codes);
        $this->assertContains('ESOCIAL_BX_BLOCKED_DAYS_INVALID', $codes);
        $this->assertContains('ESOCIAL_BX_TIMEOUTS_INVALID', $codes);
    }

    public function test_endpoint_must_match_the_exact_official_allowlist(): void
    {
        $official = config('fgts_esocial.official_bx.endpoints.restricted.identifiers');
        $this->assertSame($official, $this->config->endpoint('restricted', 'identifiers'));

        config()->set('fgts_esocial.official_bx.endpoints.restricted.identifiers', 'https://attacker.example/service');
        $this->assertContains('ESOCIAL_BX_ENDPOINT_NOT_ALLOWED', array_column($this->config->blockers(), 'code'));

        try {
            $this->config->endpoint('restricted', 'identifiers');
            $this->fail('Endpoint fora da allowlist deveria ser bloqueado.');
        } catch (EsocialBxException $exception) {
            $this->assertSame('ESOCIAL_BX_ENDPOINT_NOT_ALLOWED', $exception->stableCode);
            $this->assertTrue($exception->blocked);
        }
    }
}
