<?php

namespace Tests\Feature;

use App\Domain\Outbound\Competence;
use App\Domain\Outbound\OperationalSla;
use App\Enums\OutboundCaptureMode;
use App\Enums\OutboundDeadlineSource;
use App\Enums\OutboundDeadlineStatus;
use App\Enums\OutboundFiscalModel;
use App\Enums\OutboundMonthlyReadinessStatus;
use App\Enums\OutboundProfileStatus;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\OutboundRetrievalStatus;
use App\Enums\OutboundUrgencyBand;
use App\Enums\SvrsNfceRecoveryStatus;
use App\Enums\TenantRole;
use App\Jobs\BuildExportZipJob;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundRetrievalRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OutboundDeadlineApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'cache.default' => 'array',
            'outbound_deadline.timezone' => 'America/Sao_Paulo',
            'outbound_deadline.due_day' => 1,
            'outbound_deadline.due_time' => '23:59:59',
            'outbound_deadline.target_buffer_hours' => 48,
            'outbound_deadline.auto_queue_capacity_fraction' => 0.6,
        ]);
    }

    public function test_deadline_queries_preserve_contract_pagination_and_tenant_isolation(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $retrieval = $this->retrieval($tenant, '2026-06');
        $this->retrieval($foreignTenant, '2026-06');
        $this->authenticate($admin);
        $base = '/api/v1/outbound/deadline';

        $this->getJson($base.'/competence?competence=2026-06')
            ->assertOk()
            ->assertJsonPath('data.competence', '2026-06')
            ->assertJsonPath('data.known_total', 1)
            ->assertJsonPath('data.pending_total', 1)
            ->assertJsonPath(
                'data.completeness_scope',
                'known_documents_only',
            );
        $this->getJson($base.'/capacity?competence=2026-06')
            ->assertOk()
            ->assertJsonPath('data.competence', '2026-06')
            ->assertJsonStructure([
                'data' => [
                    'projection' => [
                        'demand_exchanges',
                        'safe_capacity_exchanges',
                        'target_at',
                        'due_at',
                    ],
                ],
            ]);
        $pending = $this->getJson(
            $base.'/pending?competence=2026-06&per_page=10&sort=id&direction=desc',
        )->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $retrieval->id)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('links');
        $this->assertNotSame(
            $retrieval->access_key,
            $pending->json('data.0.access_key_masked'),
        );
        $this->assertSame([
            'current_page',
            'last_page',
            'per_page',
            'total',
        ], array_keys($pending->json('meta')));

        $this->getJson($base.'/contingency-batch?competence=2026-06')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson($base.'/metrics?competence=2026-06')
            ->assertOk()
            ->assertJsonPath('data.known_total', 1)
            ->assertJsonPath('data.pending_total', 1);
        $this->getJson($base.'/metrics?competence=2026-13')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('competence');
    }

    public function test_deadline_mutations_enforce_roles_and_keep_dispatch_fail_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $pending = $this->retrieval($tenant, '2026-06');
        $captured = $this->retrieval(
            $tenant,
            '2026-05',
            OutboundUrgencyBand::Captured,
            SvrsNfceRecoveryStatus::Captured,
        );
        $base = '/api/v1/outbound/deadline';

        $this->authenticate($operator);
        $this->postJson($base.'/advance-target', [
            'competence' => '2026-06',
            'target_at' => now()->subDay()->toIso8601String(),
        ])->assertForbidden();
        $this->postJson($base.'/export', [
            'competence' => '2026-06',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'outbound_monthly_export_unavailable');
        Queue::assertNothingPushed();
        $this->postJson($base.'/confirm-partial', [
            'competence' => '2026-06',
            'notes' => 'Exportação parcial revisada.',
        ])->assertOk()
            ->assertJsonPath(
                'data.status',
                OutboundMonthlyReadinessStatus::PartialConfirmed->value,
            )
            ->assertJsonPath('data.pending_total', 1);

        $this->postJson($base.'/export', [
            'competence' => '2026-05',
            'include_events' => false,
        ])->assertStatus(202)
            ->assertJsonPath('data.export.status', 'PENDING')
            ->assertJsonPath('data.has_manifest', false)
            ->assertJsonPath(
                'data.completeness_scope',
                'known_documents_only',
            );
        Queue::assertPushed(BuildExportZipJob::class);

        $this->authenticate($admin);
        $sla = OperationalSla::fromConfig();
        $deadlines = $sla->deadlinesFor(Competence::fromString('2026-06'));
        $newTarget = $deadlines['target_at']->subDay();
        $this->postJson($base.'/advance-target', [
            'competence' => '2026-06',
            'target_at' => $newTarget->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('data.competence', '2026-06')
            ->assertJsonPath('data.updated_rows', 1);
        $this->assertTrue(
            $pending->refresh()->target_at->equalTo($newTarget),
        );
        $this->assertSame(
            OutboundUrgencyBand::Captured,
            $captured->refresh()->urgency_band,
        );

        $this->postJson($base.'/advance-target', [
            'competence' => '2026-06',
            'target_at' => $deadlines['due_at']->toIso8601String(),
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'outbound_target_after_due');
    }

    public function test_deadline_boundaries_reject_client_tenant_id(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $this->retrieval($tenant, '2026-06');
        $this->authenticate($admin);
        $base = '/api/v1/outbound/deadline';
        $requests = [
            ['GET', $base.'/competence', ['competence' => '2026-06']],
            ['GET', $base.'/capacity', ['competence' => '2026-06']],
            ['GET', $base.'/pending', ['competence' => '2026-06']],
            ['GET', $base.'/contingency-batch', ['competence' => '2026-06']],
            ['GET', $base.'/metrics', ['competence' => '2026-06']],
            ['POST', $base.'/confirm-partial', ['competence' => '2026-06']],
            ['POST', $base.'/export', ['competence' => '2026-06']],
            ['POST', $base.'/advance-target', [
                'competence' => '2026-06',
                'target_at' => now()->subDay()->toIso8601String(),
            ]],
        ];

        foreach ($requests as [$method, $uri, $payload]) {
            if ($method === 'GET') {
                $query = http_build_query([
                    ...$payload,
                    'tenant_id' => $tenant->id,
                ]);
                $response = $this->getJson($uri.'?'.$query);
            } else {
                $response = $this->json($method, $uri, [
                    ...$payload,
                    'tenant_id' => $tenant->id,
                ]);
            }

            $response
                ->assertUnprocessable()
                ->assertJsonValidationErrors('tenant_id');
        }

        Queue::assertNothingPushed();
    }

    private function retrieval(
        Tenant $tenant,
        string $competence,
        OutboundUrgencyBand $band = OutboundUrgencyBand::Attention,
        SvrsNfceRecoveryStatus $recovery = SvrsNfceRecoveryStatus::Eligible,
    ): OutboundRetrievalRequest {
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
            'status' => OutboundProfileStatus::Active,
        ]);
        $plan = OperationalSla::fromConfig()->deadlinesFor(
            Competence::fromString($competence),
        );

        return OutboundRetrievalRequest::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'outbound_capture_profile_id' => $profile->id,
            'establishment_id' => $establishment->id,
            'environment' => 'production',
            'model' => OutboundFiscalModel::Nfce,
            'direction' => 'OUT',
            'competence' => $competence,
            'due_at' => $plan['due_at'],
            'target_at' => $plan['target_at'],
            'deadline_source' => OutboundDeadlineSource::AccessKeyYm,
            'urgency_band' => $band,
            'deadline_status' => $band === OutboundUrgencyBand::Captured
                ? OutboundDeadlineStatus::Met
                : OutboundDeadlineStatus::Open,
            'capacity_at_risk' => false,
            'status' => OutboundRetrievalStatus::Pending,
            'mode' => OutboundCaptureMode::Assisted,
            'origin' => OutboundRetrievalOrigin::SvrsPortalByKey,
            'access_key' => '21260611222333000181650010000001011000001010',
            'root_cnpj' => $client->root_cnpj,
            'recovery_status' => $recovery,
            'attempt_count' => 0,
            'svrs_transaction_count' => 0,
            'captured_at' => $band === OutboundUrgencyBand::Captured
                ? CarbonImmutable::now('UTC')
                : null,
            'captured_before_due' => $band === OutboundUrgencyBand::Captured
                ? true
                : null,
            'capture_source' => $band === OutboundUrgencyBand::Captured
                ? 'CATALOG_FULL'
                : null,
        ]);
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }
}
