<?php

namespace Tests\Feature;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\SerproEnvironment;
use App\Jobs\Serpro\SyncClientProcuracaoJob;
use App\Models\Client;
use App\Models\ClientProcuracaoSync;
use App\Models\Tenant;
use App\Services\Integra\ClientProcuracaoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClientProcuracaoFreshnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_or_stale_sync_is_queued_without_network_access(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $service = app(ClientProcuracaoSyncService::class);

        $missing = $service->enqueueRefreshIfNeeded($tenant, $client, SerproEnvironment::Trial);
        $this->assertTrue($missing['queued']);
        $this->assertSame('SYNC_MISSING', $missing['code']);

        ClientProcuracaoSync::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('environment', SerproEnvironment::Trial->value)
            ->firstOrFail()
            ->forceFill([
                'status' => ClientProcuracaoSyncStatus::Authorized,
                'last_verified_at' => now()->subDays(8),
                'valid_to' => now()->addMonth(),
                'power_codes' => ['00103'],
            ])->save();
        $stale = $service->enqueueRefreshIfNeeded($tenant, $client, SerproEnvironment::Trial);

        $this->assertTrue($stale['queued']);
        $this->assertSame('SYNC_STALE', $stale['code']);
        $this->assertSame(
            ClientProcuracaoSyncStatus::Verifying,
            ClientProcuracaoSync::query()->withoutGlobalScopes()->firstOrFail()->status,
        );
        // ShouldBeUnique evita duplicar o mesmo par escritório/cliente/ambiente.
        Queue::assertPushed(SyncClientProcuracaoJob::class, 1);
    }

    public function test_recent_sync_is_reused_for_seven_days(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        ClientProcuracaoSync::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'environment' => SerproEnvironment::Trial,
            'status' => ClientProcuracaoSyncStatus::Authorized,
            'last_verified_at' => now()->subDays(6),
            'valid_to' => now()->addMonth(),
            'power_codes' => ['00103'],
        ]);

        $result = app(ClientProcuracaoSyncService::class)
            ->enqueueRefreshIfNeeded($tenant, $client, SerproEnvironment::Trial);

        $this->assertFalse($result['queued']);
        $this->assertSame('SYNC_FRESH', $result['code']);
        Queue::assertNothingPushed();
    }
}
