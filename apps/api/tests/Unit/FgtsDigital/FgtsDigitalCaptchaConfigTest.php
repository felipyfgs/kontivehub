<?php

declare(strict_types=1);

namespace Tests\Unit\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalPortalRequest;
use App\Enums\FgtsDigitalOperation;
use App\Services\FgtsDigital\FgtsDigitalCaptchaConfig;
use Tests\TestCase;

class FgtsDigitalCaptchaConfigTest extends TestCase
{
    private FgtsDigitalCaptchaConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = app(FgtsDigitalCaptchaConfig::class);
        config()->set('fgts_digital.captcha', [
            'driver' => 'disabled',
            'endpoint' => 'https://api.nopecha.com/token/',
            'api_key' => null,
            'proxy_url' => null,
            'timeout_seconds' => 180,
            'poll_interval_milliseconds' => 1_000,
            'max_attempts' => 1,
            'max_credits_per_run' => 5,
            'credits_per_attempt' => 5,
        ]);
    }

    public function test_disabled_is_the_fail_closed_default(): void
    {
        $this->assertNull($this->config->blocker());
        $this->assertSame(['driver' => 'disabled'], $this->config->privateTransportConfig());
        $this->assertSame([
            'driver' => 'disabled',
            'proxy_configured' => false,
            'fail_closed' => true,
        ], $this->config->publicSummary());
    }

    public function test_nopecha_requires_api_key_but_allows_external_solve_without_proxy(): void
    {
        config()->set('fgts_digital.captcha.driver', 'nopecha');
        $this->assertSame('CAPTCHA_API_KEY_MISSING', $this->config->blocker()['code']);

        config()->set('fgts_digital.captcha.api_key', 'provider-secret');
        $this->assertNull($this->config->blocker());

        config()->set('fgts_digital.captcha.proxy_url', 'http://missing-port.example');
        $this->assertSame('CAPTCHA_PROXY_INVALID', $this->config->blocker()['code']);

        config()->set('fgts_digital.captcha.proxy_url', 'http://proxy-user:proxy-secret@127.0.0.1:8080');
        $this->assertNull($this->config->blocker());
    }

    public function test_endpoint_and_credit_budget_are_allowlisted(): void
    {
        $this->enableSolver();
        config()->set('fgts_digital.captcha.endpoint', 'https://attacker.example/token/');
        $this->assertSame('CAPTCHA_ENDPOINT_NOT_ALLOWED', $this->config->blocker()['code']);

        config()->set('fgts_digital.captcha.endpoint', 'https://api.nopecha.com/token/');
        config()->set('fgts_digital.captcha.max_attempts', 2);
        $this->assertSame('CAPTCHA_BUDGET_INVALID', $this->config->blocker()['code']);
    }

    public function test_private_solver_material_stays_only_in_worker_transport_envelope(): void
    {
        $this->enableSolver();
        $transport = (new FgtsDigitalPortalRequest(
            operation: FgtsDigitalOperation::Authenticate,
            officeId: 10,
            clientId: 20,
            targetIdentifier: '12345678',
        ))->toTransportArray();

        $this->assertSame('provider-secret', $transport['captcha']['api_key']);
        $this->assertSame('http://proxy-user:proxy-secret@127.0.0.1:8080', $transport['captcha']['proxy_url']);
        $this->assertArrayNotHasKey('sitekey', $transport['captcha']);
        $this->assertArrayNotHasKey('captcha_token', $transport);
        $this->assertStringNotContainsString('provider-secret', json_encode($this->config->publicSummary(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('proxy-secret', json_encode($this->config->publicSummary(), JSON_THROW_ON_ERROR));
    }

    private function enableSolver(): void
    {
        config()->set('fgts_digital.captcha.driver', 'nopecha');
        config()->set('fgts_digital.captcha.api_key', 'provider-secret');
        config()->set('fgts_digital.captcha.proxy_url', 'http://proxy-user:proxy-secret@127.0.0.1:8080');
    }
}
