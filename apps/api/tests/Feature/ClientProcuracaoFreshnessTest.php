<?php

namespace Tests\Feature;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\SerproEnvironment;
use App\Jobs\Serpro\SyncClientProcuracaoJob;
use App\Models\Client;
use App\Models\ClientProcuracaoSnapshot;
use App\Models\Office;
use App\Services\Integra\ClientProcuracaoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClientProcuracaoFreshnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_or_stale_snapshot_is_queued_without_network_access(): void
    {
        Queue::fake();
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $service = app(ClientProcuracaoSyncService::class);

        $missing = $service->enqueueRefreshIfNeeded($office, $client, SerproEnvironment::Trial);
        $this->assertTrue($missing['queued']);
        $this->assertSame('SNAPSHOT_MISSING', $missing['code']);

        ClientProcuracaoSnapshot::query()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('environment', SerproEnvironment::Trial->value)
            ->firstOrFail()
            ->forceFill([
                'status' => ClientProcuracaoSyncStatus::Authorized,
                'last_verified_at' => now()->subDays(8),
                'valid_to' => now()->addMonth(),
                'power_codes' => ['00103'],
            ])->save();
        $stale = $service->enqueueRefreshIfNeeded($office, $client, SerproEnvironment::Trial);

        $this->assertTrue($stale['queued']);
        $this->assertSame('SNAPSHOT_STALE', $stale['code']);
        $this->assertSame(
            ClientProcuracaoSyncStatus::Verifying,
            ClientProcuracaoSnapshot::query()->firstOrFail()->status,
        );
        // ShouldBeUnique evita duplicar o mesmo par escritório/cliente/ambiente.
        Queue::assertPushed(SyncClientProcuracaoJob::class, 1);
    }

    public function test_recent_snapshot_is_reused_for_seven_days(): void
    {
        Queue::fake();
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        ClientProcuracaoSnapshot::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'environment' => SerproEnvironment::Trial,
            'status' => ClientProcuracaoSyncStatus::Authorized,
            'last_verified_at' => now()->subDays(6),
            'valid_to' => now()->addMonth(),
            'power_codes' => ['00103'],
        ]);

        $result = app(ClientProcuracaoSyncService::class)
            ->enqueueRefreshIfNeeded($office, $client, SerproEnvironment::Trial);

        $this->assertFalse($result['queued']);
        $this->assertSame('SNAPSHOT_FRESH', $result['code']);
        Queue::assertNothingPushed();
    }
}
