<?php

namespace Tests\Unit\Fiscal\SimplesMei;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\PgmeiDebtState;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiDebtProjector;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiDividaAtiva24Codec;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiPostConsultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PgmeiPostConsultServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_dividaativa_operation_is_passthrough(): void
    {
        [$request, $result] = $this->requestAndResult(year: 2024);
        $response = $this->serproRealSuccess([]);

        $out = $this->service()->handle($request, $response, $result, 'pgmei.consultar');

        $this->assertSame($result, $out['result']);
        $this->assertArrayNotHasKey('pgmei', $out['result']->normalized ?? []);
    }

    public function test_missing_year_marks_unverified(): void
    {
        [$request, $result] = $this->requestAndResult(year: null);
        $response = $this->serproRealSuccess([]);

        $out = $this->service()->handle($request, $response, $result, 'pgmei.dividaativa');

        $this->assertSame(PgmeiDebtState::Unverified->value, $out['result']->normalized['pgmei']['debt_state']);
        $this->assertSame('YEAR_MISSING', $out['result']->normalized['pgmei']['reason']);
        $this->assertFalse($out['result']->normalized['pgmei']['promoted']);
    }

    public function test_fixture_provenance_does_not_promote(): void
    {
        [$request, $result] = $this->requestAndResult(year: 2024);
        $response = new IntegraResponse(
            success: true,
            httpStatus: 200,
            body: ['dados' => []],
            dados: [],
            simulated: false,
            sourceProvenance: FiscalSourceProvenance::Fixture->value,
        );

        $out = $this->service()->handle($request, $response, $result, 'pgmei.dividaativa');

        $this->assertSame('SIMULATED_OR_NOT_PRODUCTIVE', $out['result']->normalized['pgmei']['reason']);
        $this->assertFalse($out['result']->normalized['pgmei']['promoted']);
    }

    public function test_productive_empty_list_promotes_no_active_debt(): void
    {
        [$request, $result] = $this->requestAndResult(year: 2024);
        $response = $this->serproRealSuccess([]);

        $out = $this->service()->handle($request, $response, $result, 'pgmei.dividaativa');
        $pgmei = $out['result']->normalized['pgmei'];

        $this->assertTrue($pgmei['promoted']);
        $this->assertSame(PgmeiDebtState::NoActiveDebt->value, $pgmei['debt_state']);
        $this->assertSame(0, $pgmei['items_count']);
        $this->assertSame(2024, $pgmei['calendar_year']);
    }

    private function service(): PgmeiPostConsultService
    {
        return new PgmeiPostConsultService(
            new PgmeiDividaAtiva24Codec,
            new PgmeiDebtProjector,
        );
    }

    /**
     * @return array{FiscalAdapterRequest, FiscalAdapterResult}
     */
    private function requestAndResult(?int $year): array
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_MEI',
            'service_code' => 'PGMEI',
            'operation_code' => 'MONITOR',
            'operation_key' => 'pgmei.dividaativa',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'pgmei-post:'.fake()->uuid(),
            'status' => FiscalRunStatus::Running,
            'situation' => FiscalSituation::Processing,
            'coverage' => FiscalCoverage::Unknown,
            'mutability' => FiscalMutability::ReadOnly,
            'progress' => $year === null ? [] : [
                'ano_calendario' => (string) $year,
                'anoCalendario' => (string) $year,
            ],
        ]);

        $request = new FiscalAdapterRequest(
            office: $office,
            client: $client,
            run: $run,
            systemCode: 'INTEGRA_MEI',
            serviceCode: 'PGMEI',
            operationCode: 'MONITOR',
            trigger: FiscalTrigger::Manual,
            progress: is_array($run->progress) ? $run->progress : [],
            context: $year === null ? [] : ['anoCalendario' => (string) $year],
        );

        $result = new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: FiscalSituation::UpToDate,
            coverage: FiscalCoverage::Full,
            evidenceBytes: '{}',
            normalized: [],
        );

        return [$request, $result];
    }

    private function serproRealSuccess(mixed $dados): IntegraResponse
    {
        return new IntegraResponse(
            success: true,
            httpStatus: 200,
            body: ['dados' => $dados],
            dados: $dados,
            simulated: false,
            sourceProvenance: FiscalSourceProvenance::SerproReal->value,
        );
    }
}
