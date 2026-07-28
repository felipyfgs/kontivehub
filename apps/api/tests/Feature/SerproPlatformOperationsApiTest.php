<?php

namespace Tests\Feature;

use App\Enums\SerproCredentialVersionStatus;
use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproEnvironment;
use App\Models\SerproCredentialVersion;
use App\Models\SerproRolloutApproval;
use App\Models\SerproUsageBudget;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SerproPlatformOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_reads_sanitized_operational_views_with_validated_filters(): void
    {
        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);

        $version = SerproCredentialVersion::query()->create([
            'environment' => SerproEnvironment::Trial,
            'version_number' => 1,
            'status' => SerproCredentialVersionStatus::Pending,
            'consumer_key_hint' => '****1234',
            'contractor_cnpj' => '12345678901234',
            'pfx_vault_object_id' => '01HZXVAULTPFX000000000000',
            'oauth_vault_object_id' => '01HZXVAULTOAUTH0000000000',
            'segregation_class' => SerproDataSegregationClass::HistoricalUnverified,
        ]);
        $budget = SerproUsageBudget::query()->create([
            'scope' => 'GLOBAL',
            'environment' => 'TRIAL',
            'budget_kind' => 'MONETARY',
            'limit_micros' => 10_000,
            'reserved_micros' => 2_000,
            'consumed_micros' => 3_000,
            'effective_from' => now()->subHour(),
            'is_active' => true,
        ]);
        $approval = SerproRolloutApproval::query()->create([
            'subject_type' => 'CONTRACT',
            'subject_id' => 10,
            'action' => 'ROLLOUT_PROMOTE',
            'approval_policy' => 'DUAL_ROLE',
            'environment' => SerproEnvironment::Trial,
            'status' => 'PENDING',
            'reason' => 'Promoção controlada.',
            'requested_by_user_id' => $actor->id,
            'expires_at' => now()->addDay(),
            'context' => ['access_token' => 'secret', 'safe' => 'ok'],
        ]);

        $this->getJson('/api/v1/platform/serpro/credential-versions?environment=trial')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $version->id)
            ->assertJsonPath('data.0.contractor_cnpj_masked', '1234******1234')
            ->assertJsonMissingPath('data.0.pfx_vault_object_id')
            ->assertJsonMissingPath('data.0.oauth_vault_object_id');

        $this->getJson("/api/v1/platform/serpro/credential-versions/{$version->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $version->id)
            ->assertJsonMissingPath('data.pfx_vault_object_id');

        $this->getJson('/api/v1/platform/serpro/budgets?scope=global')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $budget->id)
            ->assertJsonPath('data.0.remaining_micros', 5_000);

        $this->getJson('/api/v1/platform/serpro/rollouts?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approval->id)
            ->assertJsonPath('data.0.context.access_token', '[redacted]')
            ->assertJsonPath('data.0.context.safe', 'ok');

        $this->getJson('/api/v1/platform/serpro/readiness?environment=trial&persist=false')
            ->assertOk()
            ->assertJsonPath('data.environment', 'TRIAL')
            ->assertJsonPath('data.scope', 'GLOBAL');

        $this->getJson('/api/v1/platform/serpro/budgets?scop=GLOBAL')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scop');
        $this->getJson('/api/v1/platform/serpro/readiness?persist=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('persist');
    }

    public function test_rollout_request_approval_and_rejection_preserve_contract_and_gates(): void
    {
        $tenant = Tenant::factory()->create();
        $requester = User::factory()->asPlatformAdmin($tenant->id)->create();
        $secondApprover = User::factory()->asPlatformAdmin($tenant->id)->create();
        Sanctum::actingAs($requester);

        $created = $this->postJson('/api/v1/platform/serpro/rollouts', [
            'action' => 'ROLLOUT_PROMOTE',
            'subject_type' => 'CONTRACT',
            'subject_id' => 10,
            'reason' => 'Promoção controlada.',
            'environment' => 'trial',
            'context' => ['access_token' => 'secret', 'safe' => 'ok'],
        ])->assertCreated()
            ->assertJsonPath('data.action', 'ROLLOUT_PROMOTE')
            ->assertJsonPath('data.approval_policy', 'DUAL_ROLE')
            ->assertJsonPath('data.context.access_token', '[redacted]')
            ->assertJsonMissingPath('data.context.access_token.secret');

        $approvalId = $created->json('data.id');
        self::assertIsInt($approvalId);

        $this->postJson("/api/v1/platform/serpro/rollouts/{$approvalId}/approve")
            ->assertForbidden()
            ->assertJsonPath('code', 'password_confirmation_required')
            ->assertJsonPath('seconds_remaining', 0)
            ->assertJsonPath('window_minutes', 15);

        app(RecentPasswordConfirmationGate::class)->markConfirmed($requester);
        $this->postJson("/api/v1/platform/serpro/rollouts/{$approvalId}/approve", [
            'reason' => 'Primeira aprovação.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'PARTIAL')
            ->assertJsonPath('data.first_approver_user_id', $requester->id)
            ->assertJsonPath('executed', false)
            ->assertJsonStructure(['kill_switch']);

        Sanctum::actingAs($secondApprover);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($secondApprover);
        $this->postJson("/api/v1/platform/serpro/rollouts/{$approvalId}/approve", [
            'reason' => 'Segunda aprovação.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.second_approver_user_id', $secondApprover->id)
            ->assertJsonPath('data.fully_approved', true)
            ->assertJsonPath('executed', false);

        Sanctum::actingAs($requester);
        $rejected = $this->postJson('/api/v1/platform/serpro/rollouts', [
            'action' => 'ROLLOUT_PROMOTE',
            'subject_type' => 'CONTRACT',
            'subject_id' => 11,
            'reason' => 'Outra promoção.',
        ])->assertCreated();

        $rejectedId = $rejected->json('data.id');
        self::assertIsInt($rejectedId);

        $this->postJson("/api/v1/platform/serpro/rollouts/{$rejectedId}/reject", [
            'reason' => 'Mudança cancelada.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.reason', 'Mudança cancelada.');
    }

    public function test_rollout_domain_failure_uses_stable_api_error(): void
    {
        Sanctum::actingAs($this->platformAdmin());

        $this->postJson('/api/v1/platform/serpro/rollouts', [
            'action' => 'BILLABLE_CANARY',
            'subject_type' => 'DTE_CANARY',
            'reason' => 'Canário sem tenant.',
        ])->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Canário faturável exige tenant_id do Tenant delimitado (não injetado pelo cliente no escopo).',
                'code' => 'serpro_rollout_request_failed',
            ]);
    }

    public function test_non_platform_admin_cannot_access_operational_console(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/platform/serpro/credential-versions')->assertForbidden();
        $this->getJson('/api/v1/platform/serpro/budgets')->assertForbidden();
        $this->getJson('/api/v1/platform/serpro/rollouts')->assertForbidden();
        $this->postJson('/api/v1/platform/serpro/rollouts')->assertForbidden();
    }

    private function platformAdmin(): User
    {
        $tenant = Tenant::factory()->create();

        return User::factory()->asPlatformAdmin($tenant->id)->create();
    }
}
