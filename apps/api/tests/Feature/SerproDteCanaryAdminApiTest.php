<?php

namespace Tests\Feature;

use App\Contracts\SerproOperationExecutor;
use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproDteCanaryRequestStatus;
use App\Enums\SerproDteControlMode;
use App\Models\Client;
use App\Models\SerproDteCanaryRequest;
use App\Models\SerproDteControl;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Serpro\SerproDteCanaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class SerproDteCanaryAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_creation_and_show_use_declared_sanitized_contracts(): void
    {
        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/platform/serpro/dte-canary')
            ->assertOk()
            ->assertJsonPath('data.control.mode', SerproDteControlMode::Disabled->value)
            ->assertJsonPath('data.request', null)
            ->assertJsonPath('data.gate', null)
            ->assertJsonStructure(['data' => ['control', 'coordinates', 'request', 'gate']]);

        $this->getJson('/api/v1/platform/serpro/dte-canary?environment=PRODUCTION')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('environment');

        $this->postJson('/api/v1/platform/serpro/dte-canary')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_confirmation_required');

        app(RecentPasswordConfirmationGate::class)->markConfirmed($actor);

        $this->postJson('/api/v1/platform/serpro/dte-canary', [
            'environment' => 'PRODUCTION',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('environment');

        $created = $this->postJson('/api/v1/platform/serpro/dte-canary')
            ->assertCreated()
            ->assertJsonPath('data.environment', 'PRODUCTION')
            ->assertJsonPath('data.status', SerproDteCanaryRequestStatus::Draft->value)
            ->assertJsonPath('data.owner_approved', false)
            ->assertJsonPath('data.tenant_admin_approved', false)
            ->assertJsonMissingPath('data.fiscal_result')
            ->assertJsonMissingPath('data.dados')
            ->assertJsonMissingPath('data.mensagens');

        $requestId = (int) $created->json('data.id');

        $this->getJson("/api/v1/platform/serpro/dte-canary/{$requestId}")
            ->assertOk()
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.status', SerproDteCanaryRequestStatus::Draft->value);

        $this->getJson("/api/v1/platform/serpro/dte-canary?request_id={$requestId}")
            ->assertOk()
            ->assertJsonPath('data.request.id', $requestId)
            ->assertJsonPath('data.gate.allowed', false);
    }

    public function test_target_approval_and_execution_remain_fail_closed_before_egress(): void
    {
        $executor = Mockery::mock(SerproOperationExecutor::class);
        $executor->shouldNotReceive('execute');
        $this->app->instance(SerproOperationExecutor::class, $executor);

        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($actor);

        $canaryId = (int) $this->postJson('/api/v1/platform/serpro/dte-canary')
            ->assertCreated()
            ->json('data.id');

        $pilot = Tenant::factory()->create([
            'serpro_segregation_class' => SerproDataSegregationClass::Production,
        ]);
        $client = Client::factory()->forTenant($pilot)->create();
        $otherTenant = Tenant::factory()->create([
            'serpro_segregation_class' => SerproDataSegregationClass::Production,
        ]);
        $otherClient = Client::factory()->forTenant($otherTenant)->create();

        $this->postJson("/api/v1/platform/serpro/dte-canary/{$canaryId}/target", [
            'tenant_id' => $pilot->id,
            'client_id' => $otherClient->id,
        ])->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Cliente não pertence ao Tenant selecionado.',
                'code' => 'dte_target_error',
            ]);

        $this->postJson("/api/v1/platform/serpro/dte-canary/{$canaryId}/target", [
            'tenant_id' => $pilot->id,
            'client_id' => $client->id,
            'operation_key' => 'override.proibido',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('operation_key');

        $this->postJson("/api/v1/platform/serpro/dte-canary/{$canaryId}/target", [
            'tenant_id' => $pilot->id,
            'client_id' => $client->id,
        ])->assertOk()
            ->assertJsonPath('data.status', SerproDteCanaryRequestStatus::TargetSet->value)
            ->assertJsonPath('data.tenant_id', $pilot->id)
            ->assertJsonPath('data.client_id', $client->id);

        $this->assertDatabaseHas('serpro_dte_controls', [
            'mode' => SerproDteControlMode::Canary->value,
            'pilot_tenant_id' => $pilot->id,
            'pilot_client_id' => $client->id,
        ]);

        $this->postJson("/api/v1/platform/serpro/dte-canary/{$canaryId}/approve-owner")
            ->assertOk()
            ->assertJsonPath('data.status', SerproDteCanaryRequestStatus::PartialApproved->value)
            ->assertJsonPath('data.owner_approved', true)
            ->assertJsonPath('data.fully_approved', false);

        $this->postJson("/api/v1/platform/serpro/dte-canary/{$canaryId}/execute", [
            'tenant_id' => $pilot->id,
        ])->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Campo tenant_id não é aceito na execução do canário DTE.',
                'code' => 'forbidden_field',
            ]);

        $this->postJson("/api/v1/platform/serpro/dte-canary/{$canaryId}/execute")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'dte_execute_blocked');

        $this->assertDatabaseHas('serpro_dte_canary_requests', [
            'id' => $canaryId,
            'status' => SerproDteCanaryRequestStatus::PartialApproved->value,
            'dispatched_at' => null,
            'attempt_id' => null,
        ]);

        $this->postJson("/api/v1/platform/serpro/dte-canary/{$canaryId}/reconcile", [
            'reference' => 'AC-123',
            'summary' => 'Nenhum transporte ocorreu.',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'dte_reconcile_error');
    }

    public function test_terminal_execution_is_replayed_without_transport(): void
    {
        $executor = Mockery::mock(SerproOperationExecutor::class);
        $executor->shouldNotReceive('execute');
        $this->app->instance(SerproOperationExecutor::class, $executor);

        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($actor);

        $canary = app(SerproDteCanaryService::class)->createRequest($actor->id);
        $canary->forceFill([
            'status' => SerproDteCanaryRequestStatus::Succeeded,
            'result_status' => 'SUCCEEDED',
            'consumption_quantity' => 1,
            'finished_at' => now(),
        ])->save();

        $this->postJson("/api/v1/platform/serpro/dte-canary/{$canary->id}/execute")
            ->assertOk()
            ->assertJsonPath('data.id', $canary->id)
            ->assertJsonPath('data.status', SerproDteCanaryRequestStatus::Succeeded->value)
            ->assertJsonPath('replay', true);

        self::assertSame(1, SerproDteCanaryRequest::query()->count());
    }

    public function test_reconciled_success_can_be_promoted_and_disabled_with_bounded_input(): void
    {
        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($actor);

        $pilot = Tenant::factory()->create([
            'serpro_segregation_class' => SerproDataSegregationClass::Production,
        ]);
        $client = Client::factory()->forTenant($pilot)->create();
        $canary = app(SerproDteCanaryService::class)->createRequest($actor->id);
        $canary->forceFill([
            'status' => SerproDteCanaryRequestStatus::Reconciled,
            'tenant_id' => $pilot->id,
            'client_id' => $client->id,
            'result_status' => 'SUCCEEDED',
            'reconciliation_reference' => 'AC-321',
            'reconciliation_summary' => 'Resultado conciliado.',
            'reconciled_at' => now(),
        ])->save();

        $this->postJson("/api/v1/platform/serpro/dte-canary/{$canary->id}/promote-limited", [
            'confirmation_phrase' => SerproDteCanaryService::CONFIRM_PROMOTE_PHRASE,
            'reason' => 'Expansão limitada aprovada.',
            'max_quantity' => 4,
        ])->assertOk()
            ->assertJsonPath('data.mode', SerproDteControlMode::Limited->value)
            ->assertJsonPath('data.pilot_tenant_id', $pilot->id)
            ->assertJsonPath('data.limited_max_quantity', 4)
            ->assertJsonPath('data.remaining_quantity', 4);

        $this->postJson('/api/v1/platform/serpro/dte-canary/disable', [
            'confirmation_phrase' => 'frase-incorreta',
            'reason' => 'Teste de bloqueio.',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'dte_disable_error');

        $this->postJson('/api/v1/platform/serpro/dte-canary/disable', [
            'confirmation_phrase' => SerproDteCanaryService::CONFIRM_DISABLE_PHRASE,
            'reason' => 'Encerramento controlado.',
        ])->assertOk()
            ->assertJsonPath('data.mode', SerproDteControlMode::Disabled->value)
            ->assertJsonPath('data.disable_reason', 'Encerramento controlado.');

        self::assertSame(
            SerproDteControlMode::Disabled,
            SerproDteControl::query()->firstOrFail()->mode,
        );
    }

    public function test_platform_boundary_denies_non_platform_user(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant)->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/platform/serpro/dte-canary')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Ação restrita a administradores da plataforma.',
            ]);
    }

    private function platformAdmin(): User
    {
        $tenant = Tenant::factory()->create();

        return User::factory()->asPlatformAdmin($tenant->id)->create();
    }
}
