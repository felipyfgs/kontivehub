<?php

namespace Tests\Unit\Integra\Sitfis;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalModuleControlScope;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalVerificationState;
use App\Models\Client;
use App\Models\FiscalModuleControl;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Integra\Sitfis\SitfisSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SitfisSnapshotRefreshPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_restriction_blocks_refresh(): void
    {
        config([
            'fiscal.profile' => 'dev',
            'fiscal.kill_switch' => false,
            'fiscal_monitoring.enabled' => true,
        ]);
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $this->restrict(FiscalModuleControlScope::Global, null);

        $before = FiscalMonitoringRun::query()->count();

        try {
            app(SitfisSnapshotService::class)->refresh(
                tenant: $tenant,
                client: $client,
                dispatch: false,
            );
            $this->fail('A restrição fiscal global deveria bloquear o refresh SITFIS.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Pausa global de teste', $exception->getMessage());
        }

        $this->assertSame($before, FiscalMonitoringRun::query()->count());
    }

    public function test_tenant_restriction_blocks_refresh(): void
    {
        config([
            'fiscal.profile' => 'dev',
            'fiscal.kill_switch' => false,
            'fiscal_monitoring.enabled' => true,
        ]);
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $this->restrict(FiscalModuleControlScope::Tenant, $tenant);

        $before = FiscalMonitoringRun::query()->count();

        try {
            app(SitfisSnapshotService::class)->refresh(
                tenant: $tenant,
                client: $client,
                dispatch: false,
            );
            $this->fail('A restrição fiscal do tenant deveria bloquear o refresh SITFIS.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Pausa do tenant de teste', $exception->getMessage());
        }

        $this->assertSame($before, FiscalMonitoringRun::query()->count());
    }

    public function test_error_snapshot_within_ttl_still_enqueues(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $run = $this->makeRun($tenant, $client);

        FiscalSnapshot::query()->create([
            'tenant_id' => $tenant->id,
            'run_id' => $run->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'situation' => FiscalSituation::Error,
            'coverage' => FiscalCoverage::Full,
            'version' => 1,
            'is_current' => true,
            'normalized' => ['situation' => 'ERROR'],
            'observed_at' => now(),
            'created_at' => now(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Unverified,
        ]);

        $result = app(SitfisSnapshotService::class)->refresh(
            tenant: $tenant,
            client: $client,
            force: false,
            actorId: null,
            dispatch: false,
        );

        $this->assertTrue($result['enqueued']);
        $this->assertNotSame('WITHIN_TTL', $result['reason']);
        $this->assertNotNull($result['run']);
    }

    public function test_force_enqueues_even_when_verified_within_ttl(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $run = $this->makeRun($tenant, $client);

        FiscalSnapshot::query()->create([
            'tenant_id' => $tenant->id,
            'run_id' => $run->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'situation' => FiscalSituation::UpToDate,
            'coverage' => FiscalCoverage::Full,
            'version' => 1,
            'is_current' => true,
            'normalized' => ['situation' => 'UP_TO_DATE'],
            'observed_at' => now(),
            'created_at' => now(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Unverified,
        ]);

        // Sem evidência → display_only; force ainda deve enfileirar.
        $result = app(SitfisSnapshotService::class)->refresh(
            tenant: $tenant,
            client: $client,
            force: true,
            actorId: null,
            dispatch: false,
        );

        $this->assertTrue($result['enqueued']);
    }

    private function makeRun(Tenant $tenant, Client $client): FiscalMonitoringRun
    {
        return FiscalMonitoringRun::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'trigger' => 'MANUAL',
            'idempotency_key' => 'test-sitfis-'.uniqid(),
            'status' => 'FAILED',
            'result' => FiscalRunResult::Failed->value,
            'situation' => FiscalSituation::Error,
            'coverage' => FiscalCoverage::Full,
            'attempt' => 1,
            'correlation_id' => (string) Str::uuid(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Unverified,
        ]);
    }

    private function restrict(FiscalModuleControlScope $scope, ?Tenant $tenant): void
    {
        FiscalModuleControl::query()->create([
            'module_key' => FiscalControlModule::FiscalSituation,
            'scope' => $scope,
            'tenant_id' => $tenant?->id,
            'restricted' => true,
            'reason' => $tenant === null ? 'Pausa global de teste' : 'Pausa do tenant de teste',
            'updated_by_user_id' => User::factory()->create()->id,
        ]);
    }
}
