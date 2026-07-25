<?php

namespace Tests\Feature;

use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Models\Client;
use App\Models\MeiAutomationAttempt;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeiAutomationAttemptTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_payload_omits_internal_and_sensitive_fields(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $attempt = MeiAutomationAttempt::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'operation_key' => 'pgmei.dividaativa',
            'provider' => MeiProvider::ReceitaPortal,
            'status' => MeiAutomationStatus::Queued,
            'idempotency_key' => 'secret-idempotency-value',
            'request_fingerprint' => str_repeat('a', 64),
            'safe_metadata' => [
                'portal_version' => 'fixture',
                'cnpj' => '11222333000181',
                'raw_html' => '<html>fiscal</html>',
            ],
        ]);

        $payload = $attempt->toPublicArray();

        self::assertArrayNotHasKey('office_id', $payload);
        self::assertArrayNotHasKey('idempotency_key', $payload);
        self::assertArrayNotHasKey('request_fingerprint', $payload);
        self::assertArrayNotHasKey('external_job_id', $payload);
        self::assertSame('RECEITA_PORTAL', $payload['provider']);
        self::assertArrayNotHasKey('cnpj', $payload['metadata']);
        self::assertArrayNotHasKey('raw_html', $payload['metadata']);
    }
}
