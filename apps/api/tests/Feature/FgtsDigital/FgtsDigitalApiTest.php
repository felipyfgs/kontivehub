<?php

namespace Tests\Feature\FgtsDigital;

use App\Contracts\SecureObjectStore;
use App\DTO\FgtsDigital\FgtsDigitalSessionData;
use App\Enums\FgtsDigitalOperation;
use App\Enums\FgtsDigitalRunStatus;
use App\Enums\FiscalMutationStatus;
use App\Enums\TenantRole;
use App\Jobs\Fiscal\ExecuteFgtsDigitalRunJob;
use App\Models\Client;
use App\Models\FgtsDigitalRun;
use App\Models\FgtsDigitalSession;
use App\Models\FiscalMutationOperation;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class FgtsDigitalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('fgts_digital.driver', 'fixture');
        config()->set('fgts_digital.kill_switch', false);
        config()->set('fgts_digital.mutations_enabled', true);
        config()->set('fgts_digital.runtime.fixtures', base_path('rpa/fgts_digital/fixtures'));
    }

    public function test_admin_previews_authorizes_once_and_dispatches_horizon_job(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        Sanctum::actingAs($admin);

        $preview = $this->postJson('/api/v1/fiscal/fgts/digital/preview', [
            'client_id' => $client->id,
            'guide_type' => 'MONTHLY',
            'parameters' => [
                'competence_period_key' => '2026-07',
                'amount_cents' => 184250,
                'employee_ids' => ['12345678901'],
                'debit_ids' => ['private-debit-id'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.run.status', 'PREVIEWED')
            ->assertJsonPath('data.run.result.code', 'PREVIEW_READY');

        $runId = (int) $preview->json('data.run.id');
        $token = (string) $preview->json('data.preview_token');
        $phrase = (string) $preview->json('data.run.confirmation_phrase');
        $encoded = json_encode($preview->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('vault_object_id', $encoded);
        $this->assertStringNotContainsString('preview_token_hash', $encoded);
        $this->assertStringNotContainsString('12345678901', $encoded);
        $this->assertStringNotContainsString('private-debit-id', $encoded);
        $previewModel = FgtsDigitalRun::query()->withoutGlobalScopes()->findOrFail($runId);
        $this->assertNotNull($previewModel->request_vault_object_id);
        $this->assertSame(1, $previewModel->request_sanitized['employee_count']);
        $this->assertArrayNotHasKey('employee_ids', $previewModel->request_sanitized);

        $emit = $this->postJson('/api/v1/fiscal/fgts/digital/previews/'.$runId.'/emit', [
            'preview_token' => $token,
            'confirmation_phrase' => $phrase,
        ])->assertAccepted()
            ->assertJsonPath('data.run.status', 'AUTHORIZED')
            ->assertJsonPath('data.reused', false);
        Queue::assertPushed(ExecuteFgtsDigitalRunJob::class, 1);
        $this->assertNull($previewModel->fresh()->request_vault_object_id);
        $this->assertNotNull(FgtsDigitalRun::query()->withoutGlobalScopes()->findOrFail($emit->json('data.run.id'))->request_vault_object_id);

        $this->postJson('/api/v1/fiscal/fgts/digital/previews/'.$runId.'/emit', [
            'preview_token' => $token,
            'confirmation_phrase' => $phrase,
        ])->assertOk()->assertJsonPath('data.reused', true);
        Queue::assertPushed(ExecuteFgtsDigitalRunJob::class, 1);

        $mutation = FiscalMutationOperation::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(FiscalMutationStatus::Pending, $mutation->status);
        $this->assertTrue($mutation->confirmed_by_user);
        $this->assertSame($mutation->id, $emit->json('data.run.fiscal_mutation_operation_id'));
    }

    public function test_read_boundaries_preserve_contract_pagination_and_tenant_isolation(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $foreignClient = Client::factory()->forTenant($foreignTenant)->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        Sanctum::actingAs($viewer);

        $older = FgtsDigitalRun::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'operation' => FgtsDigitalOperation::QueryGuides,
            'status' => FgtsDigitalRunStatus::Succeeded,
            'idempotency_key' => 'fgts-contract-older',
            'request_digest' => hash('sha256', 'older'),
            'request_sanitized' => ['competence_period_key' => '2026-06'],
        ]);
        $newer = FgtsDigitalRun::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'operation' => FgtsDigitalOperation::QueryGuides,
            'status' => FgtsDigitalRunStatus::Pending,
            'idempotency_key' => 'fgts-contract-newer',
            'request_digest' => hash('sha256', 'newer'),
            'request_sanitized' => ['competence_period_key' => '2026-07'],
        ]);
        FgtsDigitalRun::query()->withoutGlobalScopes()->create([
            'tenant_id' => $foreignTenant->id,
            'client_id' => $foreignClient->id,
            'operation' => FgtsDigitalOperation::QueryGuides,
            'status' => FgtsDigitalRunStatus::Pending,
            'idempotency_key' => 'fgts-contract-foreign',
            'request_digest' => hash('sha256', 'foreign'),
        ]);

        $coverage = $this->getJson('/api/v1/fiscal/fgts/digital/coverage')
            ->assertOk()
            ->assertJsonPath('data.fail_closed', true)
            ->assertJsonPath('data.capabilities.pix_payment', false);
        $this->assertSame([
            'source',
            'driver',
            'official_portal',
            'capabilities',
            'human_challenge_policy',
            'fail_closed',
            'portal_manifest_version',
            'scheduler',
        ], array_keys($coverage->json('data')));

        $this->getJson('/api/v1/fiscal/fgts/digital/readiness?client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('data.ready_for_read', true)
            ->assertJsonPath('data.credential_source', 'CLIENT');

        $runs = $this->getJson('/api/v1/fiscal/fgts/digital/runs?per_page=1')
            ->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newer->id);
        $runs->assertJsonStructure([
            'current_page',
            'data',
            'first_page_url',
            'from',
            'last_page',
            'last_page_url',
            'links',
            'next_page_url',
            'path',
            'per_page',
            'prev_page_url',
            'to',
            'total',
        ]);
        $this->assertSame([
            'id',
            'tenant_id',
            'client_id',
            'operation',
            'guide_type',
            'status',
            'code',
            'confirmation_phrase',
            'preview_expires_at',
            'request',
            'result',
            'tax_guide_id',
            'tax_guide_version_id',
            'fiscal_mutation_operation_id',
            'correlation_id',
            'started_at',
            'finished_at',
            'created_at',
        ], array_keys($runs->json('data.0')));

        $this->getJson('/api/v1/fiscal/fgts/digital/runs?client_id='.$foreignClient->id)
            ->assertOk()
            ->assertJsonPath('total', 0);
        $this->assertNotSame($older->id, $runs->json('data.0.id'));
    }

    public function test_all_http_boundaries_reject_client_tenant_id(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        Sanctum::actingAs($admin);
        $base = '/api/v1/fiscal/fgts/digital';
        $requests = [
            ['GET', $base.'/coverage', []],
            ['GET', $base.'/readiness', ['client_id' => $client->id]],
            ['GET', $base.'/runs', []],
            ['POST', $base.'/sync', ['client_id' => $client->id]],
            ['POST', $base.'/sync-now', ['client_id' => $client->id]],
            ['POST', $base.'/preview', [
                'client_id' => $client->id,
                'guide_type' => 'MONTHLY',
                'parameters' => ['competence_period_key' => '2026-07'],
            ]],
            ['POST', $base.'/previews/1/emit', [
                'preview_token' => str_repeat('a', 48),
                'confirmation_phrase' => 'EMITIR FGTS 2026-07 MONTHLY',
            ]],
            ['POST', $base.'/sessions/import', [
                'client_id' => $client->id,
                'storage_state' => ['cookies' => [], 'origins' => []],
            ]],
            ['POST', $base.'/representations', [
                'client_id' => $client->id,
                'valid_to' => now()->addDay()->toIso8601String(),
                'confirmed' => true,
            ]],
        ];

        foreach ($requests as [$method, $uri, $payload]) {
            $response = $method === 'GET'
                ? $this->getJson($uri.'?'.http_build_query([
                    ...$payload,
                    'tenant_id' => $tenant->id,
                ]))
                : $this->json($method, $uri, [
                    ...$payload,
                    'tenant_id' => $tenant->id,
                ]);

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors('tenant_id');
        }

        Queue::assertNothingPushed();
    }

    public function test_operator_permission_and_admin_only_boundaries_are_preserved(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $operator = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'operator')
            ->create();
        Sanctum::actingAs($operator);

        $this->postJson('/api/v1/fiscal/fgts/digital/sync', [
            'client_id' => $client->id,
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'PENDING');
        Queue::assertPushed(ExecuteFgtsDigitalRunJob::class, 1);

        $this->postJson('/api/v1/fiscal/fgts/digital/sessions/import', [
            'client_id' => $client->id,
            'storage_state' => ['cookies' => [], 'origins' => []],
        ])->assertForbidden();
        $this->postJson('/api/v1/fiscal/fgts/digital/representations', [
            'client_id' => $client->id,
            'valid_to' => now()->addDay()->toIso8601String(),
            'confirmed' => true,
        ])->assertForbidden();
    }

    public function test_foreign_preview_is_not_disclosed_or_dispatched(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $foreignClient = Client::factory()->forTenant($foreignTenant)->create();
        $admin = User::factory()
            ->forTenant($tenant, TenantRole::TenantAdmin)
            ->create();
        $foreignPreview = FgtsDigitalRun::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $foreignTenant->id,
                'client_id' => $foreignClient->id,
                'operation' => FgtsDigitalOperation::Preview,
                'guide_type' => 'MONTHLY',
                'status' => FgtsDigitalRunStatus::Previewed,
                'idempotency_key' => 'fgts-foreign-preview',
                'request_digest' => hash('sha256', 'foreign-preview'),
                'preview_token_hash' => hash(
                    'sha256',
                    str_repeat('a', 48),
                ),
                'confirmation_phrase' => 'EMITIR FGTS 2026-07 MONTHLY',
                'preview_expires_at' => now()->addMinutes(5),
            ]);
        Sanctum::actingAs($admin);

        $this->postJson(
            '/api/v1/fiscal/fgts/digital/previews/'
                .$foreignPreview->id.'/emit',
            [
                'preview_token' => str_repeat('a', 48),
                'confirmation_phrase' => 'EMITIR FGTS 2026-07 MONTHLY',
            ],
        )->assertNotFound()
            ->assertJsonPath('code', 'FGTS_DIGITAL_PREVIEW_NOT_FOUND');

        Queue::assertNothingPushed();
    }

    public function test_session_storage_failure_rolls_back_database_state(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $admin = User::factory()
            ->forTenant($tenant, TenantRole::TenantAdmin)
            ->create();
        Sanctum::actingAs($admin);
        $this->app->instance(
            SecureObjectStore::class,
            new class implements SecureObjectStore
            {
                public function put(
                    string $plaintext,
                    array $metadata = [],
                ): string {
                    throw new RuntimeException(
                        'Falha privada simulada no cofre.',
                    );
                }

                public function get(
                    string $objectId,
                    array $metadata = [],
                ): string {
                    throw new RuntimeException('Operação não utilizada.');
                }

                public function delete(string $objectId): void {}

                public function exists(string $objectId): bool
                {
                    return false;
                }
            },
        );

        $response = $this->postJson(
            '/api/v1/fiscal/fgts/digital/sessions/import',
            [
                'client_id' => $client->id,
                'storage_state' => [
                    'cookies' => [[
                        'name' => 'session',
                        'value' => 'private',
                        'domain' => '.gov.br',
                        'path' => '/',
                    ]],
                    'origins' => [[
                        'origin' => 'https://fgtsdigital.sistema.gov.br',
                        'localStorage' => [],
                    ]],
                ],
            ],
        )->assertStatus(500)
            ->assertJsonPath(
                'code',
                'FGTS_DIGITAL_SESSION_STORE_FAILED',
            );

        $this->assertStringNotContainsString(
            'Falha privada simulada',
            $response->getContent(),
        );
        $this->assertDatabaseCount('fgts_digital_sessions', 0);
        Queue::assertNothingPushed();
    }

    public function test_viewer_cannot_operate_and_foreign_client_is_not_disclosed(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $foreign = Client::factory()->forTenant($other)->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/fgts/digital/readiness?client_id='.$foreign->id)->assertNotFound();
        $this->postJson('/api/v1/fiscal/fgts/digital/sync', ['client_id' => $foreign->id])->assertForbidden();
    }

    public function test_admin_imports_allowlisted_session_without_cookie_disclosure(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/fiscal/fgts/digital/sessions/import', [
            'client_id' => $client->id,
            'storage_state' => [
                'cookies' => [[
                    'name' => 'authorized_session',
                    'value' => 'never-return-this-cookie',
                    'domain' => '.gov.br',
                    'path' => '/',
                    'expires' => now()->addMinutes(20)->timestamp,
                    'httpOnly' => true,
                    'secure' => true,
                    'sameSite' => 'Lax',
                ]],
                'origins' => [['origin' => 'https://fgtsdigital.sistema.gov.br', 'localStorage' => []]],
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'READY');

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('never-return-this-cookie', $encoded);
        $this->assertStringNotContainsString('vault_object_id', $encoded);
        $this->assertNotNull(FgtsDigitalSession::query()->withoutGlobalScopes()->firstOrFail()->vault_object_id);
        $session = FgtsDigitalSession::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('EMPREGADOR', $session->profile_type);
        $this->assertSame(64, strlen($session->target_identifier_hash));
        $this->assertStringNotContainsString(
            (string) $client->root_cnpj,
            json_encode(
                FgtsDigitalSessionData::fromModel($session)->toArray(),
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function test_blocked_configuration_creates_no_run_mutation_or_queue_work(): void
    {
        Queue::fake();
        config()->set('fgts_digital.driver', 'disabled');
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/fiscal/fgts/digital/sync', ['client_id' => $client->id])
            ->assertStatus(423)
            ->assertJsonPath('code', 'FGTS_DIGITAL_DISABLED');
        $this->postJson('/api/v1/fiscal/fgts/digital/sync-now', ['client_id' => $client->id])
            ->assertStatus(423)
            ->assertJsonPath('code', 'FGTS_DIGITAL_DISABLED');
        $this->postJson('/api/v1/fiscal/fgts/digital/preview', [
            'client_id' => $client->id,
            'guide_type' => 'MONTHLY',
            'parameters' => ['competence_period_key' => '2026-07'],
        ])->assertStatus(423)->assertJsonPath('code', 'FGTS_DIGITAL_DISABLED');

        $this->assertDatabaseCount('fgts_digital_runs', 0);
        $this->assertDatabaseCount('fiscal_mutation_operations', 0);
        Queue::assertNothingPushed();
    }

    public function test_invalid_portal_host_is_reported_before_credential_resolution(): void
    {
        config()->set('fgts_digital.driver', 'portal_browser');
        config()->set('fgts_digital.egress_enabled', true);
        config()->set('fgts_digital.mutations_enabled', false);
        config()->set('fgts_digital.runtime.executable', '/bin/true');
        config()->set('fgts_digital.portal.login_url', 'https://gov.br.attacker.example/login');
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/fgts/digital/readiness?client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('data.ready_for_read', false)
            ->assertJsonPath('data.blockers.0.code', 'FGTS_DIGITAL_PORTAL_HOST_INVALID')
            ->assertJsonPath('data.certificate_source', null);
    }

    public function test_missing_representation_and_expired_session_remain_fail_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        Sanctum::actingAs($admin);

        config()->set('fgts_digital.driver', 'portal_browser');
        config()->set('fgts_digital.egress_enabled', true);
        config()->set('fgts_digital.mutations_enabled', false);
        config()->set('fgts_digital.tenant_credential_enabled', true);
        config()->set('fgts_digital.runtime.executable', '/bin/true');
        $this->getJson('/api/v1/fiscal/fgts/digital/readiness?client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('data.ready_for_read', false)
            ->assertJsonPath('data.blockers.0.code', 'FGTS_DIGITAL_CREDENTIAL_MISSING');

        config()->set('fgts_digital.driver', 'fixture');
        config()->set('fgts_digital.session.ttl_minutes', -1);
        $this->postJson('/api/v1/fiscal/fgts/digital/sessions/import', [
            'client_id' => $client->id,
            'storage_state' => [
                'cookies' => [[
                    'name' => 'session',
                    'value' => 'private',
                    'domain' => '.gov.br',
                    'path' => '/',
                ]],
                'origins' => [['origin' => 'https://fgtsdigital.sistema.gov.br', 'localStorage' => []]],
            ],
        ])->assertCreated();
        $this->getJson('/api/v1/fiscal/fgts/digital/readiness?client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('data.has_authorized_session', false);
        $this->assertSame('EXPIRED', FgtsDigitalSession::query()->withoutGlobalScopes()->firstOrFail()->status->value);
    }

    public function test_expired_preview_cannot_authorize_or_enqueue_emission(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        Sanctum::actingAs($admin);
        $preview = $this->postJson('/api/v1/fiscal/fgts/digital/preview', [
            'client_id' => $client->id,
            'guide_type' => 'PARAMETERIZED',
            'parameters' => [
                'competence_period_key' => '2026-07',
                'debit_ids' => ['private-debit'],
            ],
        ])->assertOk();

        CarbonImmutable::setTestNow(now()->addMinutes(10));
        try {
            $this->postJson('/api/v1/fiscal/fgts/digital/previews/'.$preview->json('data.run.id').'/emit', [
                'preview_token' => $preview->json('data.preview_token'),
                'confirmation_phrase' => $preview->json('data.run.confirmation_phrase'),
            ])->assertStatus(409)->assertJsonPath('code', 'FGTS_DIGITAL_PREVIEW_EXPIRED');
        } finally {
            CarbonImmutable::setTestNow();
        }
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('fgts_digital_runs', 1);
    }
}
