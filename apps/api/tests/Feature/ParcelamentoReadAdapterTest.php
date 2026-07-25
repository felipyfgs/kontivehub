<?php

namespace Tests\Feature;

use App\Contracts\ParcelamentoSource;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Enums\TaxInstallmentModality;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Services\Integra\Parcelamento\ParcelamentoOfficialCodec;
use App\Services\Integra\Parcelamento\ParcelamentoProjectionService;
use App\Services\Integra\Parcelamento\ParcelamentoReadAdapter;
use App\Services\Integra\TaxProxyPowerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParcelamentoReadAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_follows_official_call_shape_without_payment_n_plus_one(): void
    {
        config()->set('fiscal_monitoring.enabled', true);
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        $run = $this->makeRun($office, $client, TaxInstallmentModality::Parcsn);
        $source = new RecordingParcelamentoSource([
            'CONSULTAR_PEDIDOS' => [
                'success' => true,
                'body' => ['parcelamentos' => [
                    ['numero' => 1, 'situacao' => 'Encerrado', 'dataDoPedido' => 20240110],
                    ['numero' => 2, 'situacao' => 'Em andamento', 'dataDoPedido' => 20250110],
                ]],
            ],
            'CONSULTAR_PARCELAMENTO:1' => [
                'success' => true,
                'body' => ['numero' => 1, 'demonstrativoPagamentos' => [[
                    'mesDaParcela' => 202402,
                    'dataDeArrecadacao' => 20240220,
                    'valorPago' => 100,
                ]]],
            ],
            'CONSULTAR_PARCELAMENTO:2' => [
                'success' => true,
                'body' => ['numero' => 2, 'consolidacaoOriginal' => [
                    'valorTotalConsolidado' => 200,
                    'quantidadeParcelas' => 2,
                ]],
            ],
            'CONSULTAR_PARCELAS' => [
                'success' => true,
                'body' => ['listaParcelas' => [['parcela' => 202503, 'valor' => 100]]],
            ],
        ]);

        $result = $this->adapter($source)->execute(new FiscalAdapterRequest(
            $office,
            $client,
            $run,
            'INTEGRA_PARCELAMENTO',
            'PARCSN',
            'MONITOR',
            FiscalTrigger::Manual,
        ));

        $this->assertSame(FiscalCoverage::Full, $result->coverage);
        $this->assertSame(2, count($result->normalized['orders'] ?? []));
        $this->assertSame(2, $result->normalized['parcel_count']);
        $this->assertSame(1, $result->normalized['payment_count']);
        $this->assertSame([
            ['CONSULTAR_PEDIDOS', []],
            ['CONSULTAR_PARCELAMENTO', ['numeroParcelamento' => '1']],
            ['CONSULTAR_PARCELAMENTO', ['numeroParcelamento' => '2']],
            ['CONSULTAR_PARCELAS', []],
        ], $source->calls);
        $this->assertNotContains('CONSULTAR_PAGAMENTO', array_column($source->calls, 0));
    }

    public function test_detail_failure_is_partial_and_other_orders_are_projected(): void
    {
        config()->set('fiscal_monitoring.enabled', true);
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        $run = $this->makeRun($office, $client, TaxInstallmentModality::Relpsn);
        $source = new RecordingParcelamentoSource([
            'CONSULTAR_PEDIDOS' => [
                'success' => true,
                'body' => ['parcelamentos' => [
                    ['numero' => 10, 'situacao' => 'Em andamento'],
                    ['numero' => 11, 'situacao' => 'Em andamento'],
                ]],
            ],
            'CONSULTAR_PARCELAMENTO:10' => [
                'success' => false,
                'error_code' => 'TEMPORARY',
                'body' => [],
            ],
            'CONSULTAR_PARCELAMENTO:11' => [
                'success' => true,
                'body' => ['numero' => 11],
            ],
            'CONSULTAR_PARCELAS' => [
                'success' => false,
                'error_code' => 'TEMPORARY',
                'body' => [],
            ],
        ]);

        $result = $this->adapter($source)->execute(new FiscalAdapterRequest(
            $office,
            $client,
            $run,
            'INTEGRA_PARCELAMENTO',
            'RELPSN',
            'MONITOR',
            FiscalTrigger::Manual,
        ));

        $this->assertSame(2, count($result->normalized['orders'] ?? []));
        $codes = array_column($result->findings, 'code');
        $this->assertContains('PARCELAMENTO_DETALHE_PARCIAL', $codes);
        $this->assertContains('PARCELAMENTO_PARCELAS_PARCIAL', $codes);
    }

    private function adapter(ParcelamentoSource $source): ParcelamentoReadAdapter
    {
        return new ParcelamentoReadAdapter(
            $source,
            app(ParcelamentoProjectionService::class),
            app(TaxProxyPowerService::class),
            new ParcelamentoOfficialCodec,
        );
    }

    private function makeRun(Office $office, Client $client, TaxInstallmentModality $modality): FiscalMonitoringRun
    {
        return FiscalMonitoringRun::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_PARCELAMENTO',
            'service_code' => $modality->value,
            'operation_code' => 'MONITOR',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'test-'.$modality->value.'-'.$client->id,
            'status' => FiscalRunStatus::Queued,
            'situation' => FiscalSituation::Unknown,
            'coverage' => FiscalCoverage::Unknown,
            'mutability' => FiscalMutability::ReadOnly,
            'correlation_id' => 'test-correlation',
        ]);
    }
}

final class RecordingParcelamentoSource implements ParcelamentoSource
{
    /** @var list<array{0:string,1:array<string,mixed>}> */
    public array $calls = [];

    /** @param array<string, array<string, mixed>> $responses */
    public function __construct(private readonly array $responses) {}

    public function execute(
        TaxInstallmentModality $modality,
        string $operation,
        array $payload = [],
        ?FiscalAdapterRequest $request = null,
    ): array {
        $this->calls[] = [$operation, $payload];
        $key = $operation === 'CONSULTAR_PARCELAMENTO'
            ? $operation.':'.($payload['numeroParcelamento'] ?? '')
            : $operation;
        $response = $this->responses[$key] ?? ['success' => false, 'body' => []];

        return array_merge([
            'success' => false,
            'simulated' => false,
            'body' => [],
        ], $response);
    }
}
