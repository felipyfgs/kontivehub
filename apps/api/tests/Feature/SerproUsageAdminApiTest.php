<?php

namespace Tests\Feature;

use App\Enums\SerproConsumptionClass;
use App\Enums\SerproUsageResult;
use App\Models\SerproApiUsageEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SerproUsageAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_read_recompute_and_register_usage_reconciliation(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();
        Sanctum::actingAs($actor);

        SerproApiUsageEntry::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'idempotency_key' => 'usage-admin-api-test',
            'system_code' => 'INTEGRA_CONTADOR',
            'service_code' => 'CONSULTA',
            'operation_code' => 'CONSULTAR',
            'consumption_class' => SerproConsumptionClass::Consulta,
            'quantity' => 2,
            'result' => SerproUsageResult::Success,
            'estimated_cost_micros' => 1500,
            'is_billable_attempt' => true,
            'occurred_at' => '2026-07-15 12:00:00-03',
        ]);

        $this->getJson('/api/v1/platform/serpro-usage/consolidation?year=2026&month=7')
            ->assertOk()
            ->assertJsonPath('data.period_year', 2026)
            ->assertJsonPath('data.period_month', 7)
            ->assertJsonPath('data.global_aggregates', [])
            ->assertJsonPath('data.by_tenant', []);

        $this->postJson('/api/v1/platform/serpro-usage/recompute', [
            'year' => 2026,
            'month' => 7,
        ])->assertOk()
            ->assertExactJson([
                'data' => [
                    'tenant_rows' => 1,
                    'global_rows' => 2,
                ],
            ]);

        $this->assertDatabaseHas('serpro_usage_monthly_aggregates', [
            'scope' => 'TENANT',
            'tenant_id' => $tenant->id,
            'entry_count' => 1,
            'total_quantity' => 2,
            'billable_attempt_count' => 1,
        ]);

        $this->postJson('/api/v1/platform/serpro-usage/reconciliations', [
            'year' => 2026,
            'month' => 7,
            'official_total_cost_micros' => 1500,
            'official_reference' => 'invoice-2026-07',
            'recompute_aggregates' => false,
            'adjustments' => [],
        ])->assertCreated()
            ->assertJsonPath('data.period_year', 2026)
            ->assertJsonPath('data.period_month', 7)
            ->assertJsonPath('data.official_reference', 'invoice-2026-07')
            ->assertJsonPath('data.status', 'MATCHED')
            ->assertJsonPath('data.adjustments', []);

        $this->assertDatabaseHas('serpro_usage_reconciliations', [
            'period_year' => 2026,
            'period_month' => 7,
            'official_reference' => 'invoice-2026-07',
            'imported_by_user_id' => $actor->id,
        ]);
    }

    public function test_usage_endpoints_reject_invalid_or_unknown_input(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/platform/serpro-usage/consolidation?year=2026&mont=7')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mont');

        $this->postJson('/api/v1/platform/serpro-usage/recompute', [
            'year' => 2026,
            'month' => 13,
            'scope' => 'GLOBAL',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['month', 'scope']);

        $this->postJson('/api/v1/platform/serpro-usage/reconciliations', [
            'year' => 2026,
            'month' => 7,
            'official_total_cost_micros' => 100,
            'adjustments' => [[
                'amount_micros' => 100,
                'reason' => 'Correção',
                'raw_payload' => 'not-allowed',
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('adjustments.0.raw_payload');
    }

    public function test_non_platform_admin_cannot_access_usage_administration(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/platform/serpro-usage/consolidation')->assertForbidden();
        $this->postJson('/api/v1/platform/serpro-usage/recompute', [
            'year' => 2026,
            'month' => 7,
        ])->assertForbidden();
        $this->postJson('/api/v1/platform/serpro-usage/reconciliations', [
            'year' => 2026,
            'month' => 7,
            'official_total_cost_micros' => 0,
        ])->assertForbidden();
    }
}
