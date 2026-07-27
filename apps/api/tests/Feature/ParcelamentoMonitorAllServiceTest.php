<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Tenant;
use App\Services\Integra\Parcelamento\ParcelamentoMonitorAllService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ParcelamentoMonitorAllServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enqueues_exactly_the_eight_productive_modalities_idempotently(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create();
        $service = app(ParcelamentoMonitorAllService::class);

        $first = $service->enqueueClient($tenant, $client, correlationId: 'all-installments-test', dispatch: false);
        $second = $service->enqueueClient($tenant, $client, correlationId: 'all-installments-test', dispatch: false);

        $this->assertSame(8, $first['requested_modalities']);
        $this->assertSame(8, $first['accepted']);
        $this->assertSame(0, $first['failed']);
        $this->assertSame(8, FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->count());
        $this->assertSame(
            collect($first['results'])->pluck('run.id')->all(),
            collect($second['results'])->pluck('run.id')->all(),
        );
        $serviceCodes = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->pluck('service_code')
            ->sort()
            ->values()
            ->all();
        $this->assertNotContains('PARC-PAEX', $serviceCodes);
        $this->assertNotContains('PARC-SIPADE', $serviceCodes);
    }

    public function test_rejects_cross_tenant_client_before_creating_runs(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $client = Client::factory()->for($otherTenant)->create();

        try {
            app(ParcelamentoMonitorAllService::class)->enqueueClient($tenant, $client, dispatch: false);
            $this->fail('Expected cross-tenant rejection.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cliente não pertence ao escritório ativo.', $exception->getMessage());
        }

        $this->assertDatabaseCount('fiscal_monitoring_runs', 0);
    }
}
