<?php

namespace Tests\Feature;

use App\Enums\OfficeRole;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\FiscalMonitoringRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SeedsSimplesNacionalPortfolio;
use Tests\TestCase;

class PgdasdPortfolioConsultHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSimplesNacionalPortfolio;

    public function test_operator_enqueues_pgdasd_monitor_run_without_egress(): void
    {
        Queue::fake();
        Http::fake();

        $seed = $this->seedSimplesNacionalPortfolio();
        $this->actingAsOfficeUser($seed['operator']);

        $response = $this->postJson('/api/v1/fiscal/runs', [
            'client_id' => $seed['sn']->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'MONITOR',
        ])->assertCreated();

        $runId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $runId);
        $this->assertSame($seed['sn']->id, $response->json('data.client_id'));

        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->findOrFail($runId);
        $this->assertSame('INTEGRA_SN', $run->system_code);
        $this->assertSame('PGDASD', $run->service_code);

        Queue::assertPushed(
            ExecuteFiscalMonitoringRunJob::class,
            fn (ExecuteFiscalMonitoringRunJob $job): bool => $job->fiscalMonitoringRunId === $runId,
        );
        Http::assertNothingSent();

        $this->getJson('/api/v1/fiscal/runs/'.$runId)
            ->assertOk()
            ->assertJsonPath('data.id', $runId)
            ->assertJsonPath('data.client_id', $seed['sn']->id);
    }

    public function test_viewer_cannot_enqueue_pgdasd_run(): void
    {
        Queue::fake();
        Http::fake();

        $seed = $this->seedSimplesNacionalPortfolio();
        $this->actingAsOfficeUser($seed['viewer']);

        $this->postJson('/api/v1/fiscal/runs', [
            'client_id' => $seed['sn']->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'MONITOR',
        ])->assertForbidden();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_rejects_client_from_another_office(): void
    {
        Queue::fake();
        Http::fake();

        $seed = $this->seedSimplesNacionalPortfolio();
        $other = $this->seedSimplesNacionalPortfolio();
        $this->actingAsOfficeUser($seed['operator']);

        $this->postJson('/api/v1/fiscal/runs', [
            'client_id' => $other['sn']->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'MONITOR',
        ])->assertNotFound();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_show_run_is_tenant_scoped(): void
    {
        Queue::fake();
        Http::fake();

        $seed = $this->seedSimplesNacionalPortfolio();
        $other = $this->seedSimplesNacionalPortfolio();
        $this->actingAsOfficeUser($seed['operator']);

        $created = $this->postJson('/api/v1/fiscal/runs', [
            'client_id' => $seed['sn']->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'MONITOR',
        ])->assertCreated();

        $runId = (int) $created->json('data.id');

        $foreignOperator = User::factory()->forOffice($other['office'], OfficeRole::Operator)->create();
        $this->actingAsOfficeUser($foreignOperator);

        $this->getJson('/api/v1/fiscal/runs/'.$runId)
            ->assertNotFound();
        Http::assertNothingSent();
    }
}
