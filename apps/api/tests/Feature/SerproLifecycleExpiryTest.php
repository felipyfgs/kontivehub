<?php

namespace Tests\Feature;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproEnvironment;
use App\Enums\TaxProxyPowerSource;
use App\Enums\TaxProxyPowerStatus;
use App\Models\Client;
use App\Models\ClientProcuracaoSnapshot;
use App\Models\ClientProcuracaoSync;
use App\Models\Office;
use App\Models\TaxProxyPower;
use App\Services\Serpro\SerproLifecycleMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerproLifecycleExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_scan_expires_proxy_evidence_locally_and_uses_30_7_1_windows(): void
    {
        config(['serpro.lifecycle.alert_days' => [30, 7, 1]]);
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $snapshot = ClientProcuracaoSnapshot::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'environment' => SerproEnvironment::Trial,
            'status' => ClientProcuracaoSyncStatus::Authorized,
            'last_verified_at' => now()->subDay(),
            'valid_to' => now()->subDay(),
            'power_codes' => ['00103'],
        ]);
        $canonical = ClientProcuracaoSync::factory()->forClient($client)->authorized()->create([
            'valid_to' => now()->subDay(),
        ]);
        $power = TaxProxyPower::query()->create([
            'office_id' => $office->id,
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
        $this->assertSame(ClientProcuracaoSyncStatus::Expired, $snapshot->fresh()->status);
        $this->assertSame(ClientProcuracaoSyncStatus::Expired, $canonical->fresh()->status);
        $this->assertSame(1, $result['scanned']['procuracao_snapshots_expired']);
        $this->assertContains('EXPIRED', array_column($result['alerts'], 'severity'));
    }
}
