<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenantSubscriptionAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_filter_show_and_update_subscription_atomically(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Tenant comercial']);
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/platform/tenants?status=ACTIVE')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tenant->id)
            ->assertJsonPath('data.0.subscription.status', SubscriptionStatus::Active->value);

        $this->getJson("/api/v1/platform/tenants/{$tenant->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $tenant->id)
            ->assertJsonPath('data.subscription.plan', SubscriptionPlan::Professional->value);

        $this->patchJson("/api/v1/platform/tenants/{$tenant->id}/subscription", [
            'plan' => SubscriptionPlan::Enterprise->value,
            'status' => SubscriptionStatus::Suspended->value,
            'notes' => 'Suspensão administrativa.',
            'negotiated_client_limit' => 500,
        ])->assertOk()
            ->assertJsonPath('data.subscription.plan', SubscriptionPlan::Enterprise->value)
            ->assertJsonPath('data.subscription.status', SubscriptionStatus::Suspended->value)
            ->assertJsonPath('data.subscription.notes', 'Suspensão administrativa.')
            ->assertJsonPath('data.subscription.negotiated_client_limit', 500);

        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->id,
            'plan' => SubscriptionPlan::Enterprise->value,
            'status' => SubscriptionStatus::Suspended->value,
            'negotiated_client_limit' => 500,
        ]);
    }

    public function test_subscription_admin_rejects_unknown_filters_fields_and_invalid_transition(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/platform/tenants?state=ACTIVE')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('state');

        $this->patchJson("/api/v1/platform/tenants/{$tenant->id}/subscription", [
            'plan' => SubscriptionPlan::Enterprise->value,
            'billing_cycle' => 'MONTHLY',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('billing_cycle');

        $tenant->subscription->forceFill([
            'status' => SubscriptionStatus::Canceled,
            'ends_at' => now(),
        ])->save();

        $this->patchJson("/api/v1/platform/tenants/{$tenant->id}/subscription", [
            'plan' => SubscriptionPlan::Starter->value,
        ])->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Não é possível alterar plano de assinatura cancelada.',
            ]);

        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->id,
            'plan' => SubscriptionPlan::Professional->value,
            'status' => SubscriptionStatus::Canceled->value,
        ]);
    }
}
