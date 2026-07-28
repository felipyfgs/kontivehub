<?php

namespace Tests\Feature;

use App\Enums\TaxRegimeCode;
use App\Enums\TenantRole;
use App\Enums\Work\DueRuleType;
use App\Enums\Work\ProcessStatus;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientTaxRegimePeriod;
use App\Models\Establishment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Models\WorkProcess;
use App\Models\WorkProcessTemplate;
use App\Models\WorkProcessTemplateTask;
use App\Models\WorkTask;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationalWorkOrchestrationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_catalog_is_listed_and_installed_as_an_independent_tenant_copy(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $otherTenant = Tenant::factory()->create();
        WorkProcessTemplate::factory()->create([
            'tenant_id' => $otherTenant->id,
            'catalog_key' => 'PGDAS_MENSAL',
            'catalog_version' => 99,
        ]);
        $department = WorkDepartment::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Fiscal',
            'code' => 'FISCAL',
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/work/template-catalog?tenant_id='.$otherTenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $catalog = $this->getJson('/api/v1/work/template-catalog')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->json('data');
        $pgdas = collect($catalog)->firstWhere('key', 'PGDAS_MENSAL');
        $this->assertFalse($pgdas['installed']);

        $this->postJson('/api/v1/work/template-catalog/PGDAS_MENSAL/install', [
            'tenant_id' => $otherTenant->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $response = $this->postJson('/api/v1/work/template-catalog/PGDAS_MENSAL/install')
            ->assertCreated()
            ->assertJsonPath('data.catalog_key', 'PGDAS_MENSAL')
            ->assertJsonPath('data.catalog_version', 1)
            ->assertJsonPath('data.default_department_id', $department->id)
            ->assertJsonPath('data.monitoring_module_key', 'PGDASD')
            ->assertJsonCount(7, 'data.tasks');

        $templateId = (int) $response->json('data.id');
        $this->assertDatabaseHas('work_process_templates', [
            'id' => $templateId,
            'tenant_id' => $tenant->id,
            'catalog_key' => 'PGDAS_MENSAL',
            'catalog_version' => 1,
        ]);
        $this->assertDatabaseMissing('work_process_templates', [
            'id' => $templateId,
            'tenant_id' => $otherTenant->id,
        ]);

        $this->patchJson('/api/v1/work/templates/'.$templateId, [
            'name' => 'PGDAS personalizado do escritório',
            'description' => 'Fluxo personalizado',
            'monitoring_module_key' => 'PGDASD',
            'audience_rules' => [
                'tax_regimes' => ['SIMPLES_NACIONAL'],
                'category_ids' => [],
                'category_match' => 'ANY',
                'excluded_category_ids' => [],
            ],
            'default_department_id' => $department->id,
            'default_due_rule_type' => DueRuleType::FixedDayOfCompetence->value,
            'default_due_rule_value' => 20,
            'lock_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.name', 'PGDAS personalizado do escritório')
            ->assertJsonPath('data.catalog_key', 'PGDAS_MENSAL')
            ->assertJsonPath('data.lock_version', 2);

        $this->getJson('/api/v1/work/template-catalog')
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'PGDAS_MENSAL',
                'installed' => true,
                'installed_template_id' => $templateId,
                'installed_version' => 1,
                'update_available' => false,
            ]);

        $this->postJson('/api/v1/work/template-catalog/PGDAS_MENSAL/install')
            ->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_template_rejects_cross_tenant_tags_unknown_monitoring_and_viewer_mutation(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $otherTenant = Tenant::factory()->create();
        $otherCategory = $this->category($otherTenant, 'Externa');
        $template = WorkProcessTemplate::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/work/templates/'.$template->id, [
            'name' => $template->name,
            'lock_version' => $template->lock_version,
            'monitoring_module_key' => 'https://example.test/arbitrary',
        ])->assertUnprocessable();

        $this->patchJson('/api/v1/work/templates/'.$template->id, [
            'name' => $template->name,
            'lock_version' => $template->lock_version,
            'audience_rules' => [
                'tax_regimes' => [],
                'category_ids' => [$otherCategory->id],
                'category_match' => 'ANY',
                'excluded_category_ids' => [],
            ],
        ])->assertUnprocessable()
            ->assertJsonMissing(['name' => 'Externa']);

        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $viewer->forceFill(['selected_tenant_id' => $tenant->id])->saveQuietly();
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/work/template-catalog')->assertOk();
        $this->postJson('/api/v1/work/template-catalog/FOLHA_MENSAL/install')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_template_collection_preserves_paginated_contract(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $template = WorkProcessTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Rotina contratual',
        ]);
        WorkProcessTemplateTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_template_id' => $template->id,
        ]);
        WorkProcessTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Segunda rotina',
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson(
            '/api/v1/work/templates?q=contratual&per_page=1&page=1',
        )->assertOk()
            ->assertJsonPath('meta', [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 1,
                'total' => 1,
            ])
            ->assertJsonMissingPath('links')
            ->assertJsonPath('data.0.id', $template->id);

        $this->assertSame([
            'id',
            'catalog_key',
            'catalog_version',
            'name',
            'description',
            'monitoring_module_key',
            'audience_rules',
            'default_department_id',
            'default_due_rule_type',
            'default_due_rule_value',
            'is_active',
            'recurrence_enabled',
            'recurrence_frequency',
            'generation_day',
            'anchor_month',
            'period_offset',
            'next_run_at',
            'recurrence_owner_membership_id',
            'lock_version',
            'tasks',
            'created_at',
            'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertSame([
            'id',
            'sort_order',
            'title',
            'description',
            'due_rule_type',
            'due_rule_value',
            'default_department_id',
            'default_assignee_membership_id',
            'is_required',
            'is_critical',
            'requires_evidence',
        ], array_keys($response->json('data.0.tasks.0')));
    }

    public function test_structured_preview_uses_temporal_regime_tags_exceptions_and_frozen_idempotency(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $otherTenant = Tenant::factory()->create();
        Sanctum::actingAs($admin);

        $movement = $this->category($tenant, 'Com movimento');
        $excludedTag = $this->category($tenant, 'Não processar');
        $simple = $this->client($tenant, 'Simples janeiro', 'SIMPLES_NACIONAL', [$movement]);
        $presumed = $this->client($tenant, 'Presumido incluído', 'LUCRO_PRESUMIDO', [$movement]);
        $excludedByTag = $this->client($tenant, 'Excluído por tag', 'SIMPLES_NACIONAL', [$movement, $excludedTag]);
        $fallback = $this->client($tenant, 'Fallback atual', 'SIMPLES_NACIONAL', [$movement]);
        $inactive = $this->client($tenant, 'Inativo incluído', 'SIMPLES_NACIONAL', [$movement], false);
        $external = $this->client($otherTenant, 'Externo', 'SIMPLES_NACIONAL', []);

        $this->period($tenant, $simple, TaxRegimeCode::SimplesNacional, '2026-01-01', '2026-01-31');
        $this->period($tenant, $simple, TaxRegimeCode::LucroPresumido, '2026-02-01', null);
        $this->period($tenant, $presumed, TaxRegimeCode::LucroPresumido, '2026-01-01', null);
        $this->period($tenant, $excludedByTag, TaxRegimeCode::SimplesNacional, '2026-01-01', null);
        $this->period($tenant, $inactive, TaxRegimeCode::SimplesNacional, '2026-01-01', null);

        $template = $this->template($tenant, [
            'tax_regimes' => ['SIMPLES_NACIONAL'],
            'category_ids' => [$movement->id],
            'category_match' => 'ANY',
            'excluded_category_ids' => [$excludedTag->id],
        ]);

        $this->postJson('/api/v1/work/templates/'.$template->id.'/preview', [
            'tenant_id' => $otherTenant->id,
            'competence' => '2026-01',
            'selection' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $preview = $this->postJson('/api/v1/work/templates/'.$template->id.'/preview', [
            'competence' => '2026-01',
            'selection' => [
                'include_client_ids' => [$presumed->id, $fallback->id, $inactive->id, $external->id],
                'exclude_client_ids' => [$simple->id],
            ],
            'idempotency_key' => 'work-audience-preview-1',
        ])->assertCreated()
            ->assertJsonPath('data.preview_summary.total', 3)
            ->assertJsonPath('data.preview_summary.ready', 2)
            ->assertJsonPath('data.preview_summary.blocked', 1)
            ->assertJsonPath('data.preview_summary.excluded_manually', 1)
            ->assertJsonPath('data.preview_summary.invalid_references', 1);

        $this->assertSame([
            'id',
            'work_process_template_id',
            'template_lock_version',
            'competence',
            'reference_period',
            'status',
            'payload_hash',
            'idempotency_key',
            'preview_summary',
            'expires_at',
            'queued_at',
            'completed_at',
            'items',
        ], array_keys($preview->json('data')));

        $items = collect($preview->json('data.items'))->keyBy('client_id');
        $this->assertSame('MANUAL_INCLUDE', $items[$presumed->id]['preview_payload']['selection']['selection_source']);
        $this->assertSame('LUCRO_PRESUMIDO', $items[$presumed->id]['preview_payload']['selection']['tax_regime']);
        $this->assertSame('CURRENT_PROFILE_FALLBACK', $items[$fallback->id]['preview_payload']['selection']['regime_source']);
        $this->assertContains(
            'REGIME_CURRENT_FALLBACK',
            array_column($items[$fallback->id]['alerts'], 'code'),
        );
        $this->assertTrue($items[$inactive->id]['is_blocked']);
        $this->assertContains('CLIENT_INACTIVE', array_column($items[$inactive->id]['conflicts'], 'code'));
        $this->assertFalse($items->has($simple->id));
        $this->assertFalse($items->has($excludedByTag->id));
        $this->assertFalse($items->has($external->id));

        $batchId = (int) $preview->json('data.id');
        $fallback->categories()->detach();
        $fallback->forceFill(['tax_regime' => TaxRegimeCode::LucroReal->value])->save();

        $this->postJson('/api/v1/work/generation-batches/'.$batchId.'/confirm', [
            'tenant_id' => $otherTenant->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->postJson('/api/v1/work/generation-batches/'.$batchId.'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');
        $this->assertDatabaseCount('work_processes', 2);
        $this->assertDatabaseHas('work_processes', [
            'tenant_id' => $tenant->id,
            'client_id' => $fallback->id,
            'monitoring_module_key' => 'PGDASD',
        ]);
        $this->assertDatabaseMissing('work_processes', ['client_id' => $inactive->id]);

        $this->postJson('/api/v1/work/generation-batches/'.$batchId.'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');
        $this->getJson('/api/v1/work/generation-batches/'.$batchId)
            ->assertOk()
            ->assertJsonPath('data.id', $batchId)
            ->assertJsonCount(3, 'data.items');
        $this->assertDatabaseCount('work_processes', 2);
        Http::assertNothingSent();
    }

    public function test_temporal_regime_changes_selection_by_competence(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        Sanctum::actingAs($admin);
        $client = $this->client($tenant, 'Mudou de regime', 'LUCRO_PRESUMIDO', []);
        $this->period($tenant, $client, TaxRegimeCode::SimplesNacional, '2026-01-01', '2026-01-31');
        $this->period($tenant, $client, TaxRegimeCode::LucroPresumido, '2026-02-01', null);
        $template = $this->template($tenant, [
            'tax_regimes' => ['SIMPLES_NACIONAL'],
            'category_ids' => [],
            'category_match' => 'ANY',
            'excluded_category_ids' => [],
        ]);

        $this->postJson('/api/v1/work/templates/'.$template->id.'/preview', [
            'competence' => '2026-01',
            'selection' => [],
        ])->assertCreated()
            ->assertJsonPath('data.preview_summary.ready', 1)
            ->assertJsonPath('data.items.0.preview_payload.selection.regime_source', 'EFFECTIVE_PERIOD');

        $this->postJson('/api/v1/work/templates/'.$template->id.'/preview', [
            'competence' => '2026-02',
            'selection' => [],
        ])->assertCreated()
            ->assertJsonPath('data.preview_summary.total', 0)
            ->assertJsonPath('data.preview_summary.ready', 0);
        Http::assertNothingSent();
    }

    public function test_process_collection_embeds_tasks_company_and_allowlisted_monitoring_context(): void
    {
        [$viewer, $tenant] = $this->actor(TenantRole::TenantUser);
        $otherTenant = Tenant::factory()->create();
        $client = $this->client($tenant, 'Empresa operacional', 'SIMPLES_NACIONAL', []);
        Establishment::factory()->forClient($client, '11222333000181')->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'title' => 'PGDAS mensal — 2026-01',
            'monitoring_module_key' => 'PGDASD',
            'status' => ProcessStatus::EmProgresso,
        ]);
        $task = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 1,
            'title' => 'Apurar Simples Nacional',
        ]);
        WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::Concluido,
        ]);
        $external = $this->client($otherTenant, 'Empresa externa', null, []);
        WorkProcess::factory()->create([
            'tenant_id' => $otherTenant->id,
            'client_id' => $external->id,
        ]);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/work/processes?tenant_id='.$otherTenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->getJson('/api/v1/work/processes?client_id='.$client->id.'&active_only=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $process->id)
            ->assertJsonPath('data.0.client.cnpj_masked', '11.222.333/0001-81')
            ->assertJsonPath('data.0.tasks.0.id', $task->id)
            ->assertJsonPath('data.0.tasks.0.title', 'Apurar Simples Nacional')
            ->assertJsonPath('data.0.monitoring_context.module_key', 'PGDASD')
            ->assertJsonPath('data.0.monitoring_context.href', '/monitoring/clients/'.$client->id.'/pgdasd')
            ->assertJsonPath('data.0.links.client', '/clients/'.$client->id.'/cadastro');
        Http::assertNothingSent();
    }

    public function test_platform_privileged_preview_allows_null_requested_by_membership(): void
    {
        config(['features.platform_privileged_context.enabled' => true]);

        $tenant = Tenant::factory()->create();
        $client = $this->client($tenant, 'Cliente privilegiado', 'SIMPLES_NACIONAL', []);
        $template = $this->template($tenant, [
            'tax_regimes' => ['SIMPLES_NACIONAL'],
            'category_ids' => [],
            'category_match' => 'ANY',
            'excluded_category_ids' => [],
        ]);
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();

        Sanctum::actingAs($actor);
        $current = app(CurrentTenant::class);
        $current->clear();
        $current->bindPlatformPrivileged($actor, $tenant);

        $this->assertNull($current->realMembership());

        $this->postJson('/api/v1/work/templates/'.$template->id.'/preview', [
            'competence' => '2026-07',
            'selection' => [
                'rules' => ['tax_regimes' => ['SIMPLES_NACIONAL']],
                'include_client_ids' => [$client->id],
                'exclude_client_ids' => [],
            ],
            'idempotency_key' => 'work-platform-preview-1',
        ])->assertCreated()
            ->assertJsonPath('data.preview_summary.total', 1);

        $this->assertDatabaseHas('work_process_generation_batches', [
            'tenant_id' => $tenant->id,
            'idempotency_key' => 'work-platform-preview-1',
            'requested_by_membership_id' => null,
        ]);
    }

    /** @return array{User, Tenant} */
    private function actor(TenantRole $role): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, $role)->create();
        $user->forceFill(['selected_tenant_id' => $tenant->id])->saveQuietly();

        return [$user, $tenant];
    }

    private function category(Tenant $tenant, string $name): ClientCategory
    {
        return ClientCategory::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'name_key' => ClientCategory::normalizeNameKey($name),
            'color' => 'neutral',
            'is_active' => true,
        ]);
    }

    /** @param list<ClientCategory> $categories */
    private function client(
        Tenant $tenant,
        string $name,
        ?string $taxRegime,
        array $categories,
        bool $active = true,
    ): Client {
        $client = Client::factory()->forTenant($tenant)->create([
            'legal_name' => $name,
            'tax_regime' => $taxRegime,
            'is_active' => $active,
        ]);
        foreach ($categories as $category) {
            $client->categories()->attach($category->id, [
                'tenant_id' => $tenant->id,
            ]);
        }

        return $client;
    }

    private function period(
        Tenant $tenant,
        Client $client,
        TaxRegimeCode $regime,
        string $from,
        ?string $to,
    ): ClientTaxRegimePeriod {
        return ClientTaxRegimePeriod::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'regime_code' => $regime,
            'effective_from' => $from,
            'effective_to' => $to,
            'source_system' => 'TEST',
            'source_service' => 'REGIME',
            'observed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $rules */
    private function template(Tenant $tenant, array $rules): WorkProcessTemplate
    {
        $template = WorkProcessTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'PGDAS teste '.fake()->unique()->numerify('####'),
            'monitoring_module_key' => 'PGDASD',
            'audience_rules' => $rules,
            'default_due_rule_type' => DueRuleType::FixedDayOfCompetence,
            'default_due_rule_value' => 20,
            'is_active' => true,
        ]);
        WorkProcessTemplateTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_template_id' => $template->id,
            'sort_order' => 1,
            'title' => 'Apurar obrigação',
            'due_rule_type' => DueRuleType::DaysBeforeProcessDue,
            'due_rule_value' => 1,
        ]);

        return $template;
    }
}
