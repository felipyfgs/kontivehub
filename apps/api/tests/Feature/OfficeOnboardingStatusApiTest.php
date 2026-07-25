<?php

namespace Tests\Feature;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\OfficeRole;
use App\Enums\OfficeSerproOnboardingStatus;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\ClientProcuracaoSnapshot;
use App\Models\Office;
use App\Models\OfficeSerproOnboardingState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfficeOnboardingStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_credential_upload_and_replacement_require_consent_in_the_same_request(): void
    {
        $office = Office::factory()->create();
        $actor = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/office/settings/credential', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['consent_accepted']);
        $this->postJson('/api/v1/office/settings/credential/replace', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['consent_accepted']);
    }

    public function test_status_exposes_progress_modules_procuracoes_and_initial_collection_without_crossing_tenants(): void
    {
        config()->set('fiscal.profile', 'dev');
        config()->set('fiscal.kill_switch', false);

        $office = Office::factory()->create();
        $otherOffice = Office::factory()->create();
        $actor = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        $client = Client::factory()->forOffice($office)->create(['is_active' => true]);
        $otherClient = Client::factory()->forOffice($otherOffice)->create(['is_active' => true]);

        OfficeSerproOnboardingState::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'status' => OfficeSerproOnboardingStatus::Ready,
            'last_step' => 'ready',
            'ready_at' => now(),
            'metadata' => ['initial_collection_queued_at' => now()->toIso8601String()],
        ]);
        ClientProcuracaoSnapshot::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'environment' => SerproEnvironment::Trial,
            'status' => ClientProcuracaoSyncStatus::Authorized,
            'last_verified_at' => now(),
        ]);
        ClientProcuracaoSnapshot::query()->create([
            'office_id' => $otherOffice->id,
            'client_id' => $otherClient->id,
            'environment' => SerproEnvironment::Trial,
            'status' => ClientProcuracaoSyncStatus::Failed,
        ]);

        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/office/settings/onboarding-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.stage', 'PRONTO')
            ->assertJsonCount(10, 'data.modules')
            ->assertJsonPath('data.procuracoes.total_clients', 1)
            ->assertJsonPath('data.procuracoes.by_status.authorized', 1)
            ->assertJsonPath('data.procuracoes.verified', 1)
            ->assertJsonPath('data.initial_collection.runs_total', 0);
    }
}
