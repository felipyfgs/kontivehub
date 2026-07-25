<?php

namespace Tests\Feature;

use App\Enums\OfficeRole;
use App\Enums\TaxRegimeCode;
use App\Enums\Work\DueRuleType;
use App\Enums\Work\ProcessStatus;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientTaxRegimePeriod;
use App\Models\Establishment;
use App\Models\Office;
use App\Models\OperationalProcess;
use App\Models\OperationalTask;
use App\Models\ProcessTemplate;
use App\Models\ProcessTemplateTask;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Support\CurrentOffice;
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
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $otherOffice = Office::factory()->create();
        ProcessTemplate::factory()->create([
            'office_id' => $otherOffice->id,
            'catalog_key' => 'PGDAS_MENSAL',
            'catalog_version' => 99,
        ]);
        $department = WorkDepartment::factory()->create([
            'office_id' => $office->id,
            'name' => 'Fiscal',
            'code' => 'FISCAL',
        ]);
        Sanctum::actingAs($admin);

        $catalog = $this->getJson('/api/v1/work/template-catalog?office_id='.$otherOffice->id)
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->json('data');
        $pgdas = collect($catalog)->firstWhere('key', 'PGDAS_MENSAL');
        $this->assertFalse($pgdas['installed']);

        $response = $this->postJson('/api/v1/work/template-catalog/PGDAS_MENSAL/install', [
            'office_id' => $otherOffice->id,
        ])->assertCreated()
            ->assertJsonPath('data.catalog_key', 'PGDAS_MENSAL')
            ->assertJsonPath('data.catalog_version', 1)
            ->assertJsonPath('data.default_department_id', $department->id)
            ->assertJsonPath('data.monitoring_module_key', 'PGDASD')
            ->assertJsonCount(7, 'data.tasks');

        $templateId = (int) $response->json('data.id');
        $this->assertDatabaseHas('process_templates', [
            'id' => $templateId,
            'office_id' => $office->id,
            'catalog_key' => 'PGDAS_MENSAL',
            'catalog_version' => 1,
        ]);
        $this->assertDatabaseMissing('process_templates', [
            'id' => $templateId,
            'office_id' => $otherOffice->id,
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
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $otherOffice = Office::factory()->create();
        $otherCategory = $this->category($otherOffice, 'Externa');
        $template = ProcessTemplate::factory()->create(['office_id' => $office->id]);
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

        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();
        $viewer->forceFill(['selected_office_id' => $office->id])->saveQuietly();
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/work/template-catalog')->assertOk();
        $this->postJson('/api/v1/work/template-catalog/FOLHA_MENSAL/install')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_structured_preview_uses_temporal_regime_tags_exceptions_and_frozen_idempotency(): void
    {
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $otherOffice = Office::factory()->create();
        Sanctum::actingAs($admin);

        $movement = $this->category($office, 'Com movimento');
        $excludedTag = $this->category($office, 'Não processar');
        $simple = $this->client($office, 'Simples janeiro', 'SIMPLES_NACIONAL', [$movement]);
        $presumed = $this->client($office, 'Presumido incluído', 'LUCRO_PRESUMIDO', [$movement]);
        $excludedByTag = $this->client($office, 'Excluído por tag', 'SIMPLES_NACIONAL', [$movement, $excludedTag]);
        $fallback = $this->client($office, 'Fallback atual', 'SIMPLES_NACIONAL', [$movement]);
        $inactive = $this->client($office, 'Inativo incluído', 'SIMPLES_NACIONAL', [$movement], false);
        $external = $this->client($otherOffice, 'Externo', 'SIMPLES_NACIONAL', []);

        $this->period($office, $simple, TaxRegimeCode::SimplesNacional, '2026-01-01', '2026-01-31');
        $this->period($office, $simple, TaxRegimeCode::LucroPresumido, '2026-02-01', null);
        $this->period($office, $presumed, TaxRegimeCode::LucroPresumido, '2026-01-01', null);
        $this->period($office, $excludedByTag, TaxRegimeCode::SimplesNacional, '2026-01-01', null);
        $this->period($office, $inactive, TaxRegimeCode::SimplesNacional, '2026-01-01', null);

        $template = $this->template($office, [
            'tax_regimes' => ['SIMPLES_NACIONAL'],
            'category_ids' => [$movement->id],
            'category_match' => 'ANY',
            'excluded_category_ids' => [$excludedTag->id],
        ]);

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

        $this->postJson('/api/v1/work/generation-batches/'.$batchId.'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');
        $this->assertDatabaseCount('operational_processes', 2);
        $this->assertDatabaseHas('operational_processes', [
            'office_id' => $office->id,
            'client_id' => $fallback->id,
            'monitoring_module_key' => 'PGDASD',
        ]);
        $this->assertDatabaseMissing('operational_processes', ['client_id' => $inactive->id]);

        $this->postJson('/api/v1/work/generation-batches/'.$batchId.'/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');
        $this->assertDatabaseCount('operational_processes', 2);
        Http::assertNothingSent();
    }

    public function test_temporal_regime_changes_selection_by_competence(): void
    {
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($admin);
        $client = $this->client($office, 'Mudou de regime', 'LUCRO_PRESUMIDO', []);
        $this->period($office, $client, TaxRegimeCode::SimplesNacional, '2026-01-01', '2026-01-31');
        $this->period($office, $client, TaxRegimeCode::LucroPresumido, '2026-02-01', null);
        $template = $this->template($office, [
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
        [$viewer, $office] = $this->actor(OfficeRole::Viewer);
        $otherOffice = Office::factory()->create();
        $client = $this->client($office, 'Empresa operacional', 'SIMPLES_NACIONAL', []);
        Establishment::factory()->forClient($client, '11222333000181')->create();
        $process = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'title' => 'PGDAS mensal — 2026-01',
            'monitoring_module_key' => 'PGDASD',
            'status' => ProcessStatus::EmProgresso,
        ]);
        $task = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
            'sort_order' => 1,
            'title' => 'Apurar Simples Nacional',
        ]);
        OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::Concluido,
        ]);
        $external = $this->client($otherOffice, 'Empresa externa', null, []);
        OperationalProcess::factory()->create([
            'office_id' => $otherOffice->id,
            'client_id' => $external->id,
        ]);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/work/processes?client_id='.$client->id.'&active_only=1&office_id='.$otherOffice->id)
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

        $office = Office::factory()->create();
        $client = $this->client($office, 'Cliente privilegiado', 'SIMPLES_NACIONAL', []);
        $template = $this->template($office, [
            'tax_regimes' => ['SIMPLES_NACIONAL'],
            'category_ids' => [],
            'category_match' => 'ANY',
            'excluded_category_ids' => [],
        ]);
        $actor = User::factory()->asPlatformAdmin($office->id)->create();

        Sanctum::actingAs($actor);
        $current = app(CurrentOffice::class);
        $current->clear();
        $current->bindPlatformPrivileged($actor, $office);

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

        $this->assertDatabaseHas('process_generation_batches', [
            'office_id' => $office->id,
            'idempotency_key' => 'work-platform-preview-1',
            'requested_by_membership_id' => null,
        ]);
    }

    /** @return array{User, Office} */
    private function actor(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, $role)->create();
        $user->forceFill(['selected_office_id' => $office->id])->saveQuietly();

        return [$user, $office];
    }

    private function category(Office $office, string $name): ClientCategory
    {
        return ClientCategory::query()->create([
            'office_id' => $office->id,
            'name' => $name,
            'name_key' => ClientCategory::normalizeNameKey($name),
            'color' => 'neutral',
            'is_active' => true,
        ]);
    }

    /** @param list<ClientCategory> $categories */
    private function client(
        Office $office,
        string $name,
        ?string $taxRegime,
        array $categories,
        bool $active = true,
    ): Client {
        $client = Client::factory()->forOffice($office)->create([
            'legal_name' => $name,
            'tax_regime' => $taxRegime,
            'is_active' => $active,
        ]);
        foreach ($categories as $category) {
            $client->categories()->attach($category->id, [
                'office_id' => $office->id,
            ]);
        }

        return $client;
    }

    private function period(
        Office $office,
        Client $client,
        TaxRegimeCode $regime,
        string $from,
        ?string $to,
    ): ClientTaxRegimePeriod {
        return ClientTaxRegimePeriod::query()->create([
            'office_id' => $office->id,
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
    private function template(Office $office, array $rules): ProcessTemplate
    {
        $template = ProcessTemplate::factory()->create([
            'office_id' => $office->id,
            'name' => 'PGDAS teste '.fake()->unique()->numerify('####'),
            'monitoring_module_key' => 'PGDASD',
            'audience_rules' => $rules,
            'default_due_rule_type' => DueRuleType::FixedDayOfCompetence,
            'default_due_rule_value' => 20,
            'is_active' => true,
        ]);
        ProcessTemplateTask::factory()->create([
            'office_id' => $office->id,
            'process_template_id' => $template->id,
            'sort_order' => 1,
            'title' => 'Apurar obrigação',
            'due_rule_type' => DueRuleType::DaysBeforeProcessDue,
            'due_rule_value' => 1,
        ]);

        return $template;
    }
}
