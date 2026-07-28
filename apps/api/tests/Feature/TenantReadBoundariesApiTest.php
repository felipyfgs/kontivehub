<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenantReadBoundariesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_is_current_tenant_scoped(): void
    {
        [$viewer, $tenant] = $this->viewer();
        $otherTenant = Tenant::factory()->create();
        $this->authenticate($viewer);

        $this->getJson('/api/v1/tenant/subscription')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonPath(
                'data.limits.max_users',
                $tenant->subscription->max_users,
            )
            ->assertJsonMissing([$otherTenant->id]);

        $this->getJson(
            "/api/v1/tenant/subscription?tenant_id={$otherTenant->id}",
        )->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
    }

    public function test_usage_filters_are_validated_and_keep_paginator_contract(): void
    {
        [$viewer] = $this->viewer();
        $this->authenticate($viewer);

        $this->getJson('/api/v1/tenant/serpro-usage?year=2026&month=7')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson(
            '/api/v1/tenant/serpro-usage/entries?per_page=10&direction=DESC',
        )->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 10)
            ->assertJsonStructure(['data', 'first_page_url', 'last_page']);

        $this->getJson(
            '/api/v1/tenant/serpro-usage/entries?month=13&sort=unknown',
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['month', 'sort']);

        $this->getJson(
            '/api/v1/tenant/serpro-usage?tenant_id=999',
        )->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
    }

    /** @return array{User, Tenant} */
    private function viewer(): array
    {
        $tenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();

        return [$viewer, $tenant];
    }

    private function authenticate(User $actor): void
    {
        Sanctum::actingAs($actor);
        app(CurrentTenant::class)->clear();
        app()->forgetInstance(TenantAuthorization::class);
    }
}
