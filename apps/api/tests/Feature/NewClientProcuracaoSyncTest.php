<?php

namespace Tests\Feature;

use App\Enums\SerproEnvironment;
use App\Enums\TenantSerproOnboardingStatus;
use App\Jobs\Serpro\SyncClientProcuracaoJob;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\TenantSerproOnboardingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NewClientProcuracaoSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_client_created_after_ready_onboarding_queues_official_sync(): void
    {
        Queue::fake();
        config(['fiscal.profile' => 'trial']);
        $tenant = Tenant::factory()->create();
        TenantSerproOnboardingState::query()->create([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Trial,
            'status' => TenantSerproOnboardingStatus::Ready,
            'idempotency_key' => 'ready-v1',
        ]);

        $client = Client::factory()->forTenant($tenant)->create();

        Queue::assertPushed(SyncClientProcuracaoJob::class, fn (SyncClientProcuracaoJob $job): bool => $job->tenantId === (int) $tenant->id
            && $job->clientId === (int) $client->id
            && $job->environment === SerproEnvironment::Trial->value
        );
    }
}
