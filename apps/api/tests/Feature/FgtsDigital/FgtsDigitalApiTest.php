<?php

namespace Tests\Feature\FgtsDigital;

use App\Enums\FiscalMutationStatus;
use App\Enums\OfficeRole;
use App\Jobs\Fiscal\ExecuteFgtsDigitalRunJob;
use App\Models\Client;
use App\Models\FgtsDigitalRun;
use App\Models\FgtsDigitalSession;
use App\Models\FiscalMutationOperation;
use App\Models\Office;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
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
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
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

    public function test_viewer_cannot_operate_and_foreign_client_is_not_disclosed(): void
    {
        $office = Office::factory()->create();
        $other = Office::factory()->create();
        $foreign = Client::factory()->forOffice($other)->create();
        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/fgts/digital/readiness?client_id='.$foreign->id)->assertNotFound();
        $this->postJson('/api/v1/fiscal/fgts/digital/sync', ['client_id' => $foreign->id])->assertForbidden();
    }

    public function test_admin_imports_allowlisted_session_without_cookie_disclosure(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
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
        $this->assertStringNotContainsString((string) $client->root_cnpj, json_encode($session->toPublicArray(), JSON_THROW_ON_ERROR));
    }

    public function test_blocked_configuration_creates_no_run_mutation_or_queue_work(): void
    {
        Queue::fake();
        config()->set('fgts_digital.driver', 'disabled');
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
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
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/fgts/digital/readiness?client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('data.ready_for_read', false)
            ->assertJsonPath('data.blockers.0.code', 'FGTS_DIGITAL_PORTAL_HOST_INVALID')
            ->assertJsonPath('data.credential_source', null);
    }

    public function test_missing_representation_and_expired_session_remain_fail_closed(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        Sanctum::actingAs($admin);

        config()->set('fgts_digital.driver', 'portal_browser');
        config()->set('fgts_digital.egress_enabled', true);
        config()->set('fgts_digital.mutations_enabled', false);
        config()->set('fgts_digital.office_credential_enabled', true);
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
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
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
