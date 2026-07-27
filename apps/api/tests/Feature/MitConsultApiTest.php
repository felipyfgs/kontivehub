<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MitConsultApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_situacao_requires_the_official_encerramento_protocol(): void
    {
        [$user, $client] = $this->actorAndClient();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/fiscal/mit/consult', [
            'client_id' => $client->id,
            'period_key' => '2025-03',
            'operation_code' => DctfwebCodes::OP_MIT_SITUACAO,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'MIT_PROTOCOL_REQUIRED');

        Queue::assertNothingPushed();
    }

    public function test_apuracao_persists_id_apuracao_before_dispatching_the_job(): void
    {
        [$user, $client] = $this->actorAndClient();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/fiscal/mit/consult', [
            'client_id' => $client->id,
            'period_key' => '2025-03',
            'operation_code' => DctfwebCodes::OP_MIT_APURACAO,
            'id_apuracao' => 0,
            'correlation_id' => 'mit-consapuracao-316',
        ])->assertCreated()
            ->assertJsonPath('data.operation_code', DctfwebCodes::OP_MIT_APURACAO);

        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $this->assertSame(0, $run->progress['idApuracao'] ?? null);
        $this->assertSame('2025-03', $run->progress['period_key'] ?? null);

        Queue::assertPushed(
            ExecuteFiscalMonitoringRunJob::class,
            fn (ExecuteFiscalMonitoringRunJob $job): bool => $job->fiscalMonitoringRunId === $run->id,
        );
    }

    public function test_consult_rejects_a_client_from_another_tenant(): void
    {
        [$user] = $this->actorAndClient();
        $otherTenant = Tenant::factory()->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/fiscal/mit/consult', [
            'client_id' => $otherClient->id,
            'period_key' => '2025-03',
            'operation_code' => DctfwebCodes::OP_MIT_APURACAO,
            'id_apuracao' => 0,
        ])->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_consult_rejects_an_invalid_period_before_creating_a_projection(): void
    {
        [$user, $client] = $this->actorAndClient();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/fiscal/mit/consult', [
            'client_id' => $client->id,
            'period_key' => '2025-13',
            'operation_code' => DctfwebCodes::OP_MIT_APURACAO,
            'id_apuracao' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('period_key');

        $this->assertDatabaseCount('mit_assessments', 0);
        Queue::assertNothingPushed();
    }

    /** @return array{User, Client} */
    private function actorAndClient(): array
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $client = Client::factory()->forTenant($tenant)->create();

        return [$user, $client];
    }
}
