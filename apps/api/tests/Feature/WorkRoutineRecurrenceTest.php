<?php

namespace Tests\Feature;

use App\Domain\Work\ReferencePeriod;
use App\Enums\TenantRole;
use App\Enums\Work\DueRuleType;
use App\Enums\Work\GenerationBatchStatus;
use App\Enums\Work\GenerationItemStatus;
use App\Enums\Work\RecurrenceFrequency;
use App\Enums\Work\RecurrencePeriodOffset;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WorkProcess;
use App\Models\WorkProcessGenerationBatch;
use App\Models\WorkProcessTemplate;
use App\Models\WorkProcessTemplateTask;
use App\Services\Work\RoutineRecurrenceDispatcher;
use App\Support\FiscalDataModel\PrivilegedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkRoutineRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        PrivilegedTenantContext::reset();
    }

    public function test_patch_recurrence_applies_defaults_and_rejects_invalid_day(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $template = $this->template($tenant);
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/work/templates/'.$template->id.'/recurrence', [
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Monthly->value,
            'lock_version' => $template->lock_version,
        ])->assertOk()
            ->assertJsonPath('data.recurrence_enabled', true)
            ->assertJsonPath('data.recurrence_frequency', 'MONTHLY')
            ->assertJsonPath('data.generation_day', 1)
            ->assertJsonPath('data.period_offset', 'PREVIOUS');

        $this->assertNotNull($template->fresh()->next_run_at);

        $this->patchJson('/api/v1/work/templates/'.$template->id.'/recurrence', [
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Monthly->value,
            'generation_day' => 29,
            'lock_version' => $template->fresh()->lock_version,
        ])->assertUnprocessable();

        $this->getJson('/api/v1/work/templates/'.$template->id.'/recurrence')
            ->assertOk()
            ->assertJsonPath('data.generation_day', 1);
    }

    public function test_recurrence_rejects_client_tenant_scope_before_authorization(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $other = Tenant::factory()->create();
        $template = $this->template($tenant);
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $viewer->forceFill(['selected_tenant_id' => $tenant->id])->saveQuietly();

        Sanctum::actingAs($viewer);
        $this->patchJson('/api/v1/work/templates/'.$template->id.'/recurrence', [
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Monthly->value,
            'tenant_id' => $other->id,
            'lock_version' => $template->lock_version,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->patchJson('/api/v1/work/templates/'.$template->id.'/recurrence', [
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Monthly->value,
            'lock_version' => $template->lock_version,
        ])->assertForbidden();

        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/work/templates/'.$template->id.'/recurrence', [
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Monthly->value,
            'tenant_id' => $other->id,
            'recurrence_owner_membership_id' => TenantMembership::factory()->create([
                'tenant_id' => $other->id,
            ])->id,
            'lock_version' => $template->lock_version,
        ])->assertUnprocessable();
    }

    public function test_dispatcher_creates_idempotent_batch_for_previous_period(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = $this->client($tenant, 'Cliente recorrente');
        $template = $this->template($tenant, [
            'tax_regimes' => ['SIMPLES_NACIONAL'],
        ]);
        WorkProcessTemplateTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_template_id' => $template->id,
            'sort_order' => 1,
            'title' => 'Apurar',
        ]);

        $runAt = CarbonImmutable::parse('2026-07-01 03:00:00', 'UTC');
        $template->forceFill([
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Monthly,
            'generation_day' => 1,
            'period_offset' => RecurrencePeriodOffset::Previous,
            'next_run_at' => $runAt,
            'audience_rules' => [
                'tax_regimes' => ['SIMPLES_NACIONAL'],
                'category_ids' => [],
                'category_match' => 'ANY',
                'excluded_category_ids' => [],
            ],
        ])->save();

        $dispatcher = app(RoutineRecurrenceDispatcher::class);
        $result = $dispatcher->dispatchDue($runAt->addMinute());

        $this->assertSame(1, $result['dispatched']);
        $this->assertDatabaseHas('work_process_generation_batches', [
            'tenant_id' => $tenant->id,
            'work_process_template_id' => $template->id,
            'competence' => '2026-06',
            'idempotency_key' => RoutineRecurrenceDispatcher::idempotencyKey(
                (int) $tenant->id,
                (int) $template->id,
                ReferencePeriod::fromString('2026-06'),
            ),
        ]);
        $this->assertDatabaseHas('work_processes', [
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'work_process_template_id' => $template->id,
            'competence' => '2026-06',
        ]);

        $second = $dispatcher->dispatchTemplate($template->fresh(), $runAt->addMinute());
        $this->assertSame(0, $second['dispatched']);
        $this->assertDatabaseCount('work_processes', 1);
        $this->assertDatabaseCount('work_process_generation_batches', 1);
        unset($admin);
    }

    public function test_catch_up_processes_missed_periods_in_order(): void
    {
        [, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $this->client($tenant, 'Cliente catch-up');
        $template = $this->template($tenant, [
            'tax_regimes' => ['SIMPLES_NACIONAL'],
        ]);
        WorkProcessTemplateTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_template_id' => $template->id,
            'sort_order' => 1,
            'title' => 'Apurar',
        ]);

        $template->forceFill([
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Monthly,
            'generation_day' => 1,
            'period_offset' => RecurrencePeriodOffset::Previous,
            'next_run_at' => CarbonImmutable::parse('2026-05-01 03:00:00', 'UTC'),
            'audience_rules' => [
                'tax_regimes' => ['SIMPLES_NACIONAL'],
                'category_ids' => [],
                'category_match' => 'ANY',
                'excluded_category_ids' => [],
            ],
        ])->save();

        $now = CarbonImmutable::parse('2026-07-01 12:00:00', 'UTC');
        $result = app(RoutineRecurrenceDispatcher::class)->dispatchDue($now);

        $this->assertGreaterThanOrEqual(2, $result['dispatched']);
        $competences = WorkProcessGenerationBatch::query()
            ->where('work_process_template_id', $template->id)
            ->orderBy('competence')
            ->pluck('competence')
            ->all();
        $this->assertSame(['2026-04', '2026-05', '2026-06'], $competences);
        $this->assertTrue(
            CarbonImmutable::parse($template->fresh()->next_run_at)->greaterThan($now)
            || CarbonImmutable::parse($template->fresh()->next_run_at)->equalTo(
                CarbonImmutable::parse('2026-08-01 03:00:00', 'UTC'),
            ),
        );
    }

    public function test_retry_partial_failures_without_duplicating_successes(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $ok = $this->client($tenant, 'Ok');
        $fail = $this->client($tenant, 'Fail');
        $template = $this->template($tenant, [
            'tax_regimes' => ['SIMPLES_NACIONAL'],
        ]);
        WorkProcessTemplateTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_template_id' => $template->id,
            'sort_order' => 1,
            'title' => 'Apurar',
        ]);
        Sanctum::actingAs($admin);

        $preview = $this->postJson('/api/v1/work/templates/'.$template->id.'/preview', [
            'competence' => '2026-03',
            'selection' => [
                'include_client_ids' => [$ok->id, $fail->id],
            ],
            'idempotency_key' => 'retry-partial-1',
        ])->assertCreated();

        $batchId = (int) $preview->json('data.id');
        $this->postJson('/api/v1/work/generation-batches/'.$batchId.'/confirm')
            ->assertOk();

        $batch = WorkProcessGenerationBatch::query()->findOrFail($batchId);
        $failItem = $batch->items()->where('client_id', $fail->id)->firstOrFail();
        $okProcess = WorkProcess::query()
            ->where('client_id', $ok->id)
            ->where('competence', '2026-03')
            ->firstOrFail();

        // Simula falha parcial: remove processo do fail (se criado) e marca item FAILED.
        WorkProcess::query()
            ->where('client_id', $fail->id)
            ->where('competence', '2026-03')
            ->delete();
        $failItem->forceFill([
            'status' => GenerationItemStatus::Failed,
            'created_process_id' => null,
            'error_message' => 'simulated',
            'is_blocked' => false,
        ])->save();
        $batch->forceFill([
            'status' => GenerationBatchStatus::CompletedWithErrors,
            'completed_at' => now(),
        ])->save();

        $this->postJson('/api/v1/work/generation-batches/'.$batchId.'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertDatabaseCount('work_processes', 2);
        $this->assertSame(
            $okProcess->id,
            WorkProcess::query()->where('client_id', $ok->id)->value('id'),
        );
        $this->assertDatabaseHas('work_processes', [
            'client_id' => $fail->id,
            'competence' => '2026-03',
        ]);
    }

    public function test_list_batches_is_tenant_scoped(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $other = Tenant::factory()->create();
        $template = $this->template($tenant);
        $foreignTemplate = $this->template($other);

        WorkProcessGenerationBatch::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_template_id' => $template->id,
            'competence' => '2026-01',
            'status' => GenerationBatchStatus::Completed,
        ]);
        WorkProcessGenerationBatch::factory()->create([
            'tenant_id' => $other->id,
            'work_process_template_id' => $foreignTemplate->id,
            'competence' => '2026-01',
            'status' => GenerationBatchStatus::Completed,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/work/templates/'.$template->id.'/generation-batches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.competence', '2026-01')
            ->assertJsonPath('meta', [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 25,
                'total' => 1,
            ])
            ->assertJsonMissingPath('links');

        $this->assertSame([
            'id',
            'work_process_template_id',
            'competence',
            'reference_period_type',
            'status',
            'idempotency_key',
            'preview_summary',
            'queued_at',
            'completed_at',
            'created_at',
        ], array_keys($response->json('data.0')));

        $this->getJson('/api/v1/work/templates/'.$foreignTemplate->id.'/generation-batches')
            ->assertNotFound();
    }

    public function test_disabled_recurrence_skips_future_dispatch(): void
    {
        [, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $this->client($tenant, 'Cliente');
        $template = $this->template($tenant, [
            'tax_regimes' => ['SIMPLES_NACIONAL'],
        ]);
        WorkProcessTemplateTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_template_id' => $template->id,
            'sort_order' => 1,
            'title' => 'Apurar',
        ]);

        $template->forceFill([
            'recurrence_enabled' => false,
            'recurrence_frequency' => RecurrenceFrequency::Monthly,
            'next_run_at' => CarbonImmutable::parse('2026-07-01 03:00:00', 'UTC'),
            'audience_rules' => [
                'tax_regimes' => ['SIMPLES_NACIONAL'],
                'category_ids' => [],
                'category_match' => 'ANY',
                'excluded_category_ids' => [],
            ],
        ])->save();

        $result = app(RoutineRecurrenceDispatcher::class)
            ->dispatchDue(CarbonImmutable::parse('2026-07-01 12:00:00', 'UTC'));

        $this->assertSame(0, $result['dispatched']);
        $this->assertDatabaseCount('work_process_generation_batches', 0);
    }

    public function test_quarterly_dispatcher_persists_variable_period_key(): void
    {
        [, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = $this->client($tenant, 'Cliente trimestral');
        $template = $this->template($tenant, [
            'tax_regimes' => ['SIMPLES_NACIONAL'],
        ]);
        WorkProcessTemplateTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_template_id' => $template->id,
            'sort_order' => 1,
            'title' => 'Apurar T',
        ]);

        // Dia 1 de abril (âncora=1) → período anterior = 2026-T1.
        $runAt = CarbonImmutable::parse('2026-04-01 03:00:00', 'UTC');
        $template->forceFill([
            'recurrence_enabled' => true,
            'recurrence_frequency' => RecurrenceFrequency::Quarterly,
            'generation_day' => 1,
            'anchor_month' => 1,
            'period_offset' => RecurrencePeriodOffset::Previous,
            'next_run_at' => $runAt,
            'audience_rules' => [
                'tax_regimes' => ['SIMPLES_NACIONAL'],
                'category_ids' => [],
                'category_match' => 'ANY',
                'excluded_category_ids' => [],
            ],
        ])->save();

        $result = app(RoutineRecurrenceDispatcher::class)->dispatchDue($runAt->addMinute());

        $this->assertSame(1, $result['dispatched']);
        $this->assertDatabaseHas('work_process_generation_batches', [
            'tenant_id' => $tenant->id,
            'work_process_template_id' => $template->id,
            'competence' => '2026-T1',
        ]);
        $this->assertDatabaseHas('work_processes', [
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'work_process_template_id' => $template->id,
            'competence' => '2026-T1',
        ]);
    }

    /** @return array{User, Tenant} */
    private function actor(TenantRole $role): array
    {
        $tenant = Tenant::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $user = User::factory()->forTenant($tenant, $role)->create();
        $user->forceFill(['selected_tenant_id' => $tenant->id])->saveQuietly();

        return [$user, $tenant];
    }

    /** @param  array<string, mixed>  $rules */
    private function template(Tenant $tenant, array $rules = []): WorkProcessTemplate
    {
        return WorkProcessTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Rotina recorrência '.fake()->unique()->numerify('####'),
            'monitoring_module_key' => 'PGDASD',
            'audience_rules' => $rules + [
                'tax_regimes' => $rules['tax_regimes'] ?? [],
                'category_ids' => [],
                'category_match' => 'ANY',
                'excluded_category_ids' => [],
            ],
            'default_due_rule_type' => DueRuleType::FixedDayOfCompetence,
            'default_due_rule_value' => 20,
            'is_active' => true,
            'recurrence_enabled' => false,
            'generation_day' => 1,
            'period_offset' => RecurrencePeriodOffset::Previous,
        ]);
    }

    private function client(Tenant $tenant, string $name): Client
    {
        return Client::factory()->forTenant($tenant)->create([
            'legal_name' => $name,
            'tax_regime' => 'SIMPLES_NACIONAL',
            'is_active' => true,
        ]);
    }
}
