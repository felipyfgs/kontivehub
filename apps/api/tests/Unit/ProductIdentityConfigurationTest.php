<?php

namespace Tests\Unit;

use Tests\TestCase;

class ProductIdentityConfigurationTest extends TestCase
{
    public function test_public_identity_urls_sender_and_session_cookie_are_canonical(): void
    {
        $this->assertSame('KontiveHub', config('app.name'));
        $this->assertSame('https://api.kontivehub.com.br', config('app.url'));
        $this->assertSame('https://app.kontivehub.com.br', config('app.frontend_url'));
        $this->assertSame('https://portal.kontivehub.com.br', config('app.portal_url'));
        $this->assertSame('noreply@kontivehub.com.br', config('mail.from.address'));
        $this->assertSame('KontiveHub', config('mail.from.name'));

        $this->assertSame('kontivehub_session', config('session.cookie'));
        $this->assertSame('.kontivehub.com.br', config('session.domain'));
        $this->assertTrue(config('session.secure'));
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_sanctum_cors_and_reverb_use_the_approved_origins(): void
    {
        $stateful = config('sanctum.stateful');
        $cors = config('cors.allowed_origins');
        $reverb = config('reverb.apps.apps.0.allowed_origins');

        $this->assertContains('app.kontivehub.com.br', $stateful);
        $this->assertContains('portal.kontivehub.com.br', $stateful);
        $this->assertContains('api.kontivehub.com.br', $stateful);
        $this->assertNotContains('app.inovaicontabil.com.br', $stateful);

        $this->assertSame([
            'https://app.kontivehub.com.br',
            'https://portal.kontivehub.com.br',
        ], $cors);
        $this->assertSame($cors, $reverb);
        $this->assertTrue(config('cors.supports_credentials'));
        $this->assertNotContains('*', $cors);
    }

    public function test_sensitive_communication_flags_remain_fail_closed(): void
    {
        $this->assertFalse(config('communication.enabled'));
        $this->assertFalse(config('communication.gateway.enabled'));
        $this->assertFalse(config('communication.flows.enabled'));
        $this->assertFalse(config('communication.flows.runtime_enabled'));
    }
}
