<?php

namespace Tests\Unit\Communication;

use Tests\TestCase;

final class WazyncConfigurationTest extends TestCase
{
    public function test_wazync_is_disabled_by_default_and_uses_the_private_compose_dns(): void
    {
        $this->assertFalse(config('communication.gateway.enabled'));
        $this->assertSame('http://wazync:8080', config('communication.gateway.base_url'));
        $this->assertSame(10, config('communication.gateway.timeout_seconds'));
    }

    public function test_versioned_configuration_uses_only_wazync_environment_variables(): void
    {
        $legacyPrefix = implode('_', ['WHATSAPP', 'GATEWAY']);

        foreach ([
            base_path('.env.example'),
            config_path('communication.php'),
            base_path('phpunit.xml'),
        ] as $path) {
            $contents = file_get_contents($path);

            $this->assertIsString($contents);
            $this->assertStringContainsString('WAZYNC_', $contents);
            $this->assertStringNotContainsString($legacyPrefix, $contents);
        }
    }
}
