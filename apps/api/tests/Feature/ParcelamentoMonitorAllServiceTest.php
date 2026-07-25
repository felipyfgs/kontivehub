<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Services\Integra\Parcelamento\ParcelamentoMonitorAllService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ParcelamentoMonitorAllServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enqueues_exactly_the_eight_productive_modalities_idempotently(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        $service = app(ParcelamentoMonitorAllService::class);

        $first = $service->enqueueClient($office, $client, correlationId: 'all-installments-test', dispatch: false);
        $second = $service->enqueueClient($office, $client, correlationId: 'all-installments-test', dispatch: false);

        $this->assertSame(8, $first['requested_modalities']);
        $this->assertSame(8, $first['accepted']);
        $this->assertSame(0, $first['failed']);
        $this->assertSame(8, FiscalMonitoringRun::query()->where('office_id', $office->id)->count());
        $this->assertSame(
            collect($first['results'])->pluck('run.id')->all(),
            collect($second['results'])->pluck('run.id')->all(),
        );
        $serviceCodes = FiscalMonitoringRun::query()->pluck('service_code')->sort()->values()->all();
        $this->assertNotContains('PARC-PAEX', $serviceCodes);
        $this->assertNotContains('PARC-SIPADE', $serviceCodes);
    }

    public function test_rejects_cross_tenant_client_before_creating_runs(): void
    {
        $office = Office::factory()->create();
        $otherOffice = Office::factory()->create();
        $client = Client::factory()->for($otherOffice)->create();

        try {
            app(ParcelamentoMonitorAllService::class)->enqueueClient($office, $client, dispatch: false);
            $this->fail('Expected cross-tenant rejection.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cliente não pertence ao escritório ativo.', $exception->getMessage());
        }

        $this->assertDatabaseCount('fiscal_monitoring_runs', 0);
    }
}
