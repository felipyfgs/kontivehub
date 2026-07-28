<?php

namespace Tests\Feature;

use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproDteCanaryRequestStatus;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\SerproDteCanaryRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Serpro\SerproDteCanaryException;
use App\Services\Serpro\SerproDteCanaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SerproDteCanaryTenantApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_confirms_current_tenant_without_trusting_client_tenant_id(): void
    {
        [$canary, $pilot, $owner] = $this->targetedCanary();
        $service = app(SerproDteCanaryService::class);
        try {
            $service->approveAsOwner($canary, $owner);
            self::fail('A aprovação direta deveria exigir senha recente.');
        } catch (SerproDteCanaryException $error) {
            self::assertStringContainsString('Reconfirmação de senha', $error->getMessage());
        }
        app(RecentPasswordConfirmationGate::class)->markConfirmed($owner);
        $service->approveAsOwner($canary, $owner);

        $admin = User::factory()
            ->forTenant($pilot, TenantRole::TenantAdmin)
            ->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/serpro/dte-canary/pending')
            ->assertOk()
            ->assertJsonPath('data.id', $canary->id)
            ->assertJsonPath('data.tenant_id', $pilot->id)
            ->assertJsonPath('data.status', SerproDteCanaryRequestStatus::PartialApproved->value);

        $this->postJson("/api/v1/serpro/dte-canary/{$canary->id}/confirm")
            ->assertForbidden()
            ->assertJsonPath('code', 'password_confirmation_required');

        app(RecentPasswordConfirmationGate::class)->markConfirmed($admin);

        $this->postJson("/api/v1/serpro/dte-canary/{$canary->id}/confirm", [
            'tenant_id' => $pilot->id,
        ])->assertUnprocessable()
            ->assertExactJson([
                'message' => 'tenant_id do client não é aceito; use o Tenant corrente.',
                'code' => 'forbidden_field',
            ]);

        $this->assertDatabaseHas('serpro_dte_canary_requests', [
            'id' => $canary->id,
            'tenant_admin_approver_user_id' => null,
        ]);

        $this->postJson("/api/v1/serpro/dte-canary/{$canary->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', SerproDteCanaryRequestStatus::FullyApproved->value)
            ->assertJsonPath('data.tenant_admin_approved', true)
            ->assertJsonPath('data.fully_approved', true);

        $this->getJson("/api/v1/serpro/dte-canary/{$canary->id}/result")
            ->assertOk()
            ->assertJsonPath('data.id', $canary->id)
            ->assertJsonPath('data.fiscal_result', null);

        $this->getJson("/api/v1/serpro/dte-canary/{$canary->id}/result?tenant_id={$pilot->id}")
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'tenant_id do client não é aceito.',
                'code' => 'forbidden_field',
            ]);
    }

    public function test_result_is_denied_outside_pilot_tenant_and_pending_can_be_null(): void
    {
        [$canary] = $this->targetedCanary();
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->forTenant($otherTenant)->create();
        Sanctum::actingAs($otherUser);

        $this->getJson('/api/v1/serpro/dte-canary/pending')
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->getJson("/api/v1/serpro/dte-canary/{$canary->id}/result")
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Resultado DTE indisponível para o Tenant corrente.',
                'code' => 'dte_result_forbidden',
            ]);
    }

    /**
     * @return array{0: SerproDteCanaryRequest, 1: Tenant, 2: User}
     */
    private function targetedCanary(): array
    {
        $defaultTenant = Tenant::factory()->create();
        $owner = User::factory()->asPlatformAdmin($defaultTenant->id)->create();
        $pilot = Tenant::factory()->create([
            'serpro_segregation_class' => SerproDataSegregationClass::Production,
        ]);
        $client = Client::factory()->forTenant($pilot)->create();
        $service = app(SerproDteCanaryService::class);
        $canary = $service->createRequest($owner->id);
        $canary = $service->selectTarget(
            $canary,
            $pilot->id,
            $client->id,
            $owner->id,
        );

        return [$canary, $pilot, $owner];
    }
}
