<?php

namespace Tests\Feature;

use App\Enums\SerproContractStatus;
use App\Enums\SerproEnvironment;
use App\Models\SerproContract;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SerproContractAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_reads_only_sanitized_contract_health_and_catalog_data(): void
    {
        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);

        $contract = SerproContract::query()->create([
            'environment' => SerproEnvironment::Trial,
            'status' => SerproContractStatus::Pending,
            'contractor_cnpj' => '12345678901234',
            'contractor_name' => 'Contratante',
            'fingerprint_sha256' => str_repeat('a', 64),
            'pfx_vault_object_id' => '01HZXVAULTPFX000000000000',
            'oauth_vault_object_id' => '01HZXVAULTOAUTH0000000000',
            'health_status' => 'PENDING',
        ]);

        $this->getJson('/api/v1/platform/serpro/contracts?environment=trial')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $contract->id)
            ->assertJsonPath('data.0.environment', 'TRIAL')
            ->assertJsonPath('data.0.contractor_cnpj_masked', '1234******1234')
            ->assertJsonMissingPath('data.0.pfx_vault_object_id')
            ->assertJsonMissingPath('data.0.oauth_vault_object_id');

        $this->getJson("/api/v1/platform/serpro/contracts/{$contract->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $contract->id)
            ->assertJsonPath('data.has_pfx', true)
            ->assertJsonPath('data.has_oauth', true)
            ->assertJsonMissingPath('data.pfx_vault_object_id');

        $this->getJson('/api/v1/platform/serpro/health?environment=trial')
            ->assertOk()
            ->assertJsonPath('data.environment', 'TRIAL')
            ->assertJsonCount(1, 'data.contracts');

        $this->getJson('/api/v1/platform/serpro/catalog?environment=production')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_kill_switch_activation_is_immediate_and_deactivation_is_atomic(): void
    {
        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/platform/serpro/kill-switch', [
            'active' => true,
            'reason' => 'Interrupção preventiva',
        ])->assertOk()
            ->assertJsonPath('data.global.active', true)
            ->assertJsonPath('data.global.durable', true);

        $this->assertDatabaseHas('serpro_runtime_controls', [
            'control_key' => 'kill_switch.global',
            'active' => true,
        ]);

        app(RecentPasswordConfirmationGate::class)->markConfirmed($actor);
        $window = $this->activeChangeWindow();

        $this->postJson('/api/v1/platform/serpro/kill-switch', [
            'active' => false,
            'reason' => 'Retomada controlada',
            'confirmation_phrase' => 'CONFIRMO-OUTRA-OPERACAO',
            ...$window,
        ])->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Confirmação do proprietário inválida ou não executada.',
                'code' => 'owner_confirmation_failed',
            ]);

        $this->assertDatabaseCount('serpro_rollout_approvals', 0);
        $this->assertDatabaseHas('serpro_runtime_controls', [
            'control_key' => 'kill_switch.global',
            'active' => true,
        ]);

        $this->postJson('/api/v1/platform/serpro/kill-switch', [
            'active' => false,
            'reason' => 'Retomada controlada',
            'confirmation_phrase' => 'CONFIRMO-KILL_SWITCH_OFF',
            ...$window,
        ])->assertOk()
            ->assertJsonPath('data.global.active', false)
            ->assertJsonPath('executed', true)
            ->assertJsonPath('approval.status', 'EXECUTED')
            ->assertJsonPath('message', 'Kill switch global desativado.');

        $this->assertDatabaseHas('serpro_runtime_controls', [
            'control_key' => 'kill_switch.global',
            'active' => false,
        ]);
        $this->assertDatabaseHas('serpro_rollout_approvals', [
            'action' => 'KILL_SWITCH_OFF',
            'status' => 'EXECUTED',
            'requested_by_user_id' => $actor->id,
            'first_approver_user_id' => $actor->id,
        ]);
    }

    public function test_sensitive_mutations_require_valid_input_and_recent_password(): void
    {
        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/platform/serpro/contracts?environmnt=TRIAL')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('environmnt');

        $this->postJson('/api/v1/platform/serpro/kill-switch', [
            'active' => false,
            'reason' => 'Retomada',
            'confirmation_phrase' => 'CONFIRMO-KILL_SWITCH_OFF',
            ...$this->activeChangeWindow(),
        ])->assertForbidden()
            ->assertJsonPath('code', 'password_confirmation_required');

        $this->postJson('/api/v1/platform/serpro/breaker/reset', [
            'reason' => 'Dependência normalizada',
            'force' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('force');

        $this->postJson('/api/v1/platform/serpro/breaker/reset', [
            'reason' => 'Dependência normalizada',
        ])->assertOk()
            ->assertJsonPath('data.state', 'closed')
            ->assertJsonPath('data.failures', 0);
    }

    public function test_non_platform_admin_cannot_access_contract_administration(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/platform/serpro/contracts')->assertForbidden();
        $this->getJson('/api/v1/platform/serpro/kill-switch')->assertForbidden();
        $this->postJson('/api/v1/platform/serpro/kill-switch', [
            'active' => true,
            'reason' => 'Não autorizado',
        ])->assertForbidden();
        $this->postJson('/api/v1/platform/serpro/breaker/reset', [
            'reason' => 'Não autorizado',
        ])->assertForbidden();
    }

    /** @return array{change_window_start: string, change_window_end: string} */
    private function activeChangeWindow(): array
    {
        return [
            'change_window_start' => now()->subMinute()->toIso8601String(),
            'change_window_end' => now()->addMinutes(30)->toIso8601String(),
        ];
    }

    private function platformAdmin(): User
    {
        $tenant = Tenant::factory()->create();

        return User::factory()->asPlatformAdmin($tenant->id)->create();
    }
}
