<?php

namespace Tests\Feature;

use App\Enums\OfficeSerproOnboardingStatus;
use App\Enums\SerproEnvironment;
use App\Jobs\Serpro\SyncClientProcuracaoJob;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeSerproOnboardingState;
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
        $office = Office::factory()->create();
        OfficeSerproOnboardingState::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'status' => OfficeSerproOnboardingStatus::Ready,
            'idempotency_key' => 'ready-v1',
        ]);

        $client = Client::factory()->forOffice($office)->create();

        Queue::assertPushed(SyncClientProcuracaoJob::class, fn (SyncClientProcuracaoJob $job): bool => $job->officeId === (int) $office->id
            && $job->clientId === (int) $client->id
            && $job->environment === SerproEnvironment::Trial->value
        );
    }
}
