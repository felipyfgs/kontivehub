<?php

namespace Tests\Feature;

use App\Enums\OutboundCaptureMode;
use App\Enums\OutboundCaptureRunStatus;
use App\Enums\OutboundFiscalModel;
use App\Enums\OutboundNumberStatus;
use App\Enums\OutboundProfileStatus;
use App\Enums\OutboundSeriesStatus;
use App\Enums\TenantRole;
use App\Jobs\QueryOutboundSequenceJob;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundCaptureRun;
use App\Models\OutboundNumberState;
use App\Models\OutboundSeriesCursor;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OutboundCaptureApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'cache.default' => 'array',
            'sefaz.ma_outbound.enabled' => false,
            'sefaz.ma_outbound.protocol_query_enabled' => false,
            'sefaz.ma_outbound.kill_switch' => false,
            'sefaz.ma_outbound.mutating_probe_enabled' => false,
        ]);
        Cache::flush();
    }

    public function test_capture_queries_preserve_contract_and_tenant_isolation(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        [$profile, $series, $number, $run] = $this->captureGraph($tenant);
        [$foreignProfile] = $this->captureGraph($foreignTenant);
        $this->authenticate($admin);

        $profiles = $this->getJson('/api/v1/outbound/profiles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $profile->id)
            ->assertJsonMissingPath('data.0.csc_vault_object_id');
        $this->assertSame([
            'id',
            'client_id',
            'establishment_id',
            'uf',
            'environment',
            'model',
            'mode',
            'status',
            'consent_recorded',
            'mandate_reference',
            'allowlisted',
            'kill_switch',
            'csc',
            'activated_at',
        ], array_keys($profiles->json('data.0')));

        $this->getJson('/api/v1/outbound/profiles/'.$profile->id)
            ->assertOk()
            ->assertJsonPath('data.id', $profile->id);
        $this->getJson('/api/v1/outbound/profiles/'.$profile->id.'/series')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $series->id)
            ->assertJsonPath('data.0.position_kind', 'nNF');
        $this->getJson('/api/v1/outbound/series/'.$series->id.'/numbers?gaps_only=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $number->id);
        $this->getJson('/api/v1/outbound/runs?series_cursor_id='.$series->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $run->id);
        $this->getJson('/api/v1/outbound/kill-switch')
            ->assertOk()
            ->assertJsonPath('data.global_active', false)
            ->assertJsonPath('data.enabled', false);

        $this->getJson('/api/v1/outbound/profiles/'.$foreignProfile->id)
            ->assertNotFound();
    }

    public function test_capture_mutations_enforce_role_recent_password_and_fail_closed_flags(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        [$profile, $series] = $this->captureGraph($tenant);
        $base = '/api/v1/outbound';

        $this->authenticate($operator);
        $this->postJson($base.'/profiles/'.$profile->id.'/activate', [
            'mandate_reference' => 'mandato-001',
        ])->assertForbidden();
        $this->postJson($base.'/series/'.$series->id.'/reset', [
            'reason' => 'Correção operacional',
            'discovery_position' => 200,
            'confirm' => true,
        ])->assertForbidden();
        $this->postJson($base.'/kill-switch', [
            'active' => true,
            'reason' => 'Interrupção preventiva',
            'profile_id' => $profile->id,
        ])->assertForbidden();
        $this->authenticate($admin);
        $this->postJson($base.'/kill-switch', [
            'active' => true,
            'reason' => 'Interrupção global indevida',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('profile_id');
        $this->authenticate($operator);
        $this->postJson($base.'/series/'.$series->id.'/trigger-query')
            ->assertForbidden()
            ->assertJsonPath('code', 'outbound_protocol_query_disabled');
        Queue::assertNothingPushed();

        config(['sefaz.ma_outbound.protocol_query_enabled' => true]);
        $this->postJson($base.'/series/'.$series->id.'/trigger-query')
            ->assertOk()
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.series_id', $series->id);
        Queue::assertPushed(
            QueryOutboundSequenceJob::class,
            fn (QueryOutboundSequenceJob $job): bool => $job->seriesCursorId === $series->id
                && $job->userId === $operator->id,
        );

        $this->authenticate($admin);
        $this->getJson($base.'/profiles/'.$profile->id.'/csc')
            ->assertForbidden();
        app(RecentPasswordConfirmationGate::class)->markConfirmed($admin);
        $this->getJson($base.'/profiles/'.$profile->id.'/csc')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.csc', null);

        $this->postJson($base.'/profiles/'.$profile->id.'/activate', [
            'mandate_reference' => 'mandato-001',
            'allowlisted' => true,
        ])->assertOk()
            ->assertJsonPath('data.status', OutboundProfileStatus::Active->value)
            ->assertJsonPath('data.allowlisted', true);
        $this->assertSame(
            OutboundProfileStatus::Active,
            $profile->refresh()->status,
        );

        $this->postJson($base.'/series/'.$series->id.'/reset', [
            'reason' => 'Correção operacional',
            'discovery_position' => 200,
            'confirm' => true,
        ])->assertOk()
            ->assertJsonPath('data.discovery_position', 200)
            ->assertJsonPath('data.status', OutboundSeriesStatus::Idle->value);

        $this->postJson($base.'/kill-switch', [
            'active' => true,
            'reason' => 'Interrupção preventiva',
            'profile_id' => $profile->id,
        ])->assertOk()
            ->assertJsonPath('data.kill_switch', true)
            ->assertJsonPath('data.status', OutboundProfileStatus::KillSwitched->value);
    }

    public function test_capture_boundaries_reject_client_tenant_id(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        [$profile, $series] = $this->captureGraph($tenant);
        $establishment = Establishment::query()
            ->withoutGlobalScopes()
            ->findOrFail($profile->establishment_id);
        $this->authenticate($admin);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($admin);
        $base = '/api/v1/outbound';
        $requests = [
            ['GET', $base.'/profiles', []],
            ['GET', $base.'/profiles/'.$profile->id, []],
            ['POST', $base.'/establishments/'.$establishment->id.'/seed', [
                'environment' => 'production',
                'xml' => '<nfeProc />',
            ]],
            ['GET', $base.'/profiles/'.$profile->id.'/csc', []],
            ['POST', $base.'/profiles/'.$profile->id.'/csc', [
                'csc' => 'secret-token',
                'csc_id' => '000001',
            ]],
            ['POST', $base.'/profiles/'.$profile->id.'/activate', [
                'mandate_reference' => 'mandato-001',
            ]],
            ['POST', $base.'/profiles/'.$profile->id.'/package', [
                'files' => [UploadedFile::fake()->create('package.zip', 10)],
            ]],
            ['GET', $base.'/profiles/'.$profile->id.'/series', []],
            ['GET', $base.'/series/'.$series->id.'/numbers', []],
            ['POST', $base.'/series/'.$series->id.'/reset', [
                'reason' => 'Correção operacional',
                'discovery_position' => 100,
                'confirm' => true,
            ]],
            ['POST', $base.'/series/'.$series->id.'/trigger-query', []],
            ['GET', $base.'/runs', []],
            ['GET', $base.'/kill-switch', []],
            ['POST', $base.'/kill-switch', [
                'active' => true,
                'reason' => 'Interrupção preventiva',
            ]],
        ];

        foreach ($requests as [$method, $uri, $payload]) {
            $response = $method === 'GET'
                ? $this->getJson($uri.'?tenant_id='.$tenant->id)
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

    /**
     * @return array{
     *   OutboundCaptureProfile,
     *   OutboundSeriesCursor,
     *   OutboundNumberState,
     *   OutboundCaptureRun
     * }
     */
    private function captureGraph(Tenant $tenant): array
    {
        $client = Client::factory()->forTenant($tenant)->create();
        $establishment = Establishment::factory()->forClient($client)->create();
        $profile = OutboundCaptureProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'establishment_id' => $establishment->id,
            'uf' => 'MA',
            'environment' => 'production',
            'model' => OutboundFiscalModel::Nfce,
            'mode' => OutboundCaptureMode::Assisted,
            'status' => OutboundProfileStatus::SeedReady,
        ]);
        $series = OutboundSeriesCursor::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'outbound_capture_profile_id' => $profile->id,
            'establishment_id' => $establishment->id,
            'environment' => 'production',
            'model' => OutboundFiscalModel::Nfce,
            'series' => 1,
            'seed_nnf' => 100,
            'discovery_position' => 101,
            'highest_confirmed_nnf' => 100,
            'status' => OutboundSeriesStatus::SeedReady,
        ]);
        $number = OutboundNumberState::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'outbound_capture_profile_id' => $profile->id,
            'outbound_series_cursor_id' => $series->id,
            'series' => 1,
            'nnf' => 101,
            'status' => OutboundNumberStatus::GapPending,
        ]);
        $run = OutboundCaptureRun::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'outbound_capture_profile_id' => $profile->id,
            'outbound_series_cursor_id' => $series->id,
            'status' => OutboundCaptureRunStatus::Completed,
            'triggered_by' => 'test',
        ]);

        return [$profile, $series, $number, $run];
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }
}
