<?php

namespace Tests\Feature;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproEnvironment;
use App\Enums\TaxProxyPowerSource;
use App\Enums\TaxProxyPowerStatus;
use App\Models\Client;
use App\Models\ClientProcuracaoSync;
use App\Models\TaxProxyPower;
use App\Models\Tenant;
use App\Services\Serpro\SerproLifecycleMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerproLifecycleExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_scan_expires_proxy_evidence_locally_and_uses_30_7_1_windows(): void
    {
        config(['serpro.lifecycle.alert_days' => [30, 7, 1]]);
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $sync = ClientProcuracaoSync::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'environment' => SerproEnvironment::Trial,
            'status' => ClientProcuracaoSyncStatus::Authorized,
            'last_verified_at' => now()->subDay(),
            'valid_to' => now()->subDay(),
            'power_codes' => ['00103'],
        ]);
        $power = TaxProxyPower::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'environment' => SerproEnvironment::Trial,
            'author_identity' => '11222333000181',
            'contributor_cnpj' => '11222333000181',
            'system_code' => 'DCTFWEB',
            'power_code' => '00103',
            'source' => TaxProxyPowerSource::IntegraProcuracoes,
            'provenance' => 'API_VERIFIED',
            'status' => TaxProxyPowerStatus::Active,
            'valid_to' => now()->subDay(),
            'segregation_class' => SerproDataSegregationClass::Production,
        ]);

        $result = app(SerproLifecycleMonitor::class)->scan();

        $this->assertSame(TaxProxyPowerStatus::Expired, $power->fresh()->status);
        $this->assertSame(ClientProcuracaoSyncStatus::Expired, $sync->fresh()->status);
        $this->assertSame(1, $result['scanned']['procuracao_syncs_expired']);
        $this->assertContains('EXPIRED', array_column($result['alerts'], 'severity'));
    }
}
