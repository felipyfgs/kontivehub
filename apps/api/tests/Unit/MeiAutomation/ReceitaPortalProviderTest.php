<?php

namespace Tests\Unit\MeiAutomation;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\Enums\FiscalTrigger;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Services\MeiAutomation\Providers\ReceitaPortalProvider;
use Tests\TestCase;

class ReceitaPortalProviderTest extends TestCase
{
    public function test_disabled_egress_returns_portal_unavailable_fallback_eligible(): void
    {
        config([
            'mei_automation.fixture_enabled' => false,
            'mei_automation.live_egress_enabled' => false,
        ]);

        $outcome = app(ReceitaPortalProvider::class)->execute(
            $this->request(),
            'pgmei.consultar',
        );

        $this->assertSame('PORTAL_UNAVAILABLE', $outcome->result->errorCode);
        $this->assertTrue($outcome->fallbackEligible);
        $this->assertSame('PORTAL_UNAVAILABLE', $outcome->fallbackReason);
        $this->assertFalse($outcome->submitted);
    }

    private function request(): FiscalAdapterRequest
    {
        $office = new Office;
        $office->id = 7;
        $client = new Client;
        $client->id = 11;
        $client->office_id = 7;
        $run = new FiscalMonitoringRun;
        $run->forceFill([
            'id' => 13,
            'office_id' => 7,
            'client_id' => 11,
            'system_code' => 'INTEGRA_MEI',
            'service_code' => 'PGMEI',
            'operation_code' => 'CONSULTAR',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'portal-provider:12345678',
        ]);

        return new FiscalAdapterRequest(
            office: $office,
            client: $client,
            run: $run,
            systemCode: 'INTEGRA_MEI',
            serviceCode: 'PGMEI',
            operationCode: 'CONSULTAR',
            trigger: FiscalTrigger::Manual,
        );
    }
}
