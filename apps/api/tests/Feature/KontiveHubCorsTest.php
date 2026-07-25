<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KontiveHubCorsTest extends TestCase
{
    #[DataProvider('approvedOrigins')]
    public function test_preflight_allows_approved_credentialed_origins(string $origin): void
    {
        $response = $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Content-Type, X-XSRF-TOKEN',
        ])->options('/api/v1/me');

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', $origin);
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    #[DataProvider('rejectedOrigins')]
    public function test_preflight_does_not_authorize_external_or_legacy_origins(string $origin): void
    {
        $response = $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/v1/me');

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    public static function approvedOrigins(): array
    {
        return [
            'app' => ['https://app.kontivehub.com.br'],
            'portal' => ['https://portal.kontivehub.com.br'],
        ];
    }

    public static function rejectedOrigins(): array
    {
        return [
            'legacy' => ['https://app.inovaicontabil.com.br'],
            'external' => ['https://attacker.example'],
        ];
    }
}
