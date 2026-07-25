<?php

namespace Tests\Unit\MeiAutomation;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Services\MeiAutomation\MeiAutomationAttemptService;
use App\Services\MeiAutomation\MeiPortalResultTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeiPortalResultTranslatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_dasn_never_becomes_full_or_up_to_date(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $run = $this->monitoringRun($office, $client, 'DASN_SIMEI');
        $attempt = app(MeiAutomationAttemptService::class)->start(
            $office,
            $client,
            'dasnsimei.consultimadecrec',
            MeiProvider::ReceitaPortal,
            'translator:12345678',
            ['cnpj' => str_repeat('1', 14), 'calendar_year' => 2024],
            $run,
        );
        $attempt->forceFill([
            'status' => MeiAutomationStatus::Succeeded,
            'result_payload_encrypted' => [
                'coverage' => 'SUMMARY',
                'parser_version' => 'dasnsimei-1',
                'portal_version' => 'fixture-1',
                'declarations' => [[
                    'calendar_year' => 2024,
                    'status' => 'Transmitida',
                    'transmitted_at' => '2025-05-15',
                    'coverage' => 'SUMMARY',
                    'receipt_available' => true,
                    'receipt_artifact_id' => null,
                ]],
            ],
        ])->save();

        $result = app(MeiPortalResultTranslator::class)->translate(
            new FiscalAdapterRequest(
                office: $office,
                client: $client,
                run: $run,
                systemCode: 'INTEGRA_MEI',
                serviceCode: 'DASN_SIMEI',
                operationCode: 'CONSULTAR',
                trigger: FiscalTrigger::Manual,
            ),
            $attempt->refresh(),
        );

        self::assertSame(FiscalCoverage::Partial, $result->coverage);
        self::assertSame(FiscalSituation::Unknown, $result->situation);
        self::assertSame('SUMMARY', $result->normalized['coverage']);
        self::assertSame(
            FiscalSourceProvenance::ReceitaPortal,
            $run->refresh()->source_provenance,
        );
    }

    public function test_pending_dasn_creates_structured_fiscal_pending_finding(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $run = $this->monitoringRun($office, $client, 'DASN_SIMEI');
        $attempt = app(MeiAutomationAttemptService::class)->start(
            $office,
            $client,
            'dasnsimei.consultimadecrec',
            MeiProvider::ReceitaPortal,
            'translator:pending',
            ['cnpj' => str_repeat('1', 14), 'calendar_year' => 2025],
            $run,
        );
        $attempt->forceFill([
            'status' => MeiAutomationStatus::Succeeded,
            'result_payload_encrypted' => [
                'coverage' => 'SUMMARY',
                'parser_version' => 'dasnsimei-1',
                'portal_version' => 'fixture-1',
                'declarations' => [[
                    'calendar_year' => 2025,
                    'status' => 'Não apresentada',
                    'transmitted_at' => null,
                    'declaration_type' => 'Original',
                    'special_situation' => 'Extinção',
                    'special_situation_date' => '2026-05-20',
                    'pending' => true,
                    'coverage' => 'SUMMARY',
                    'receipt_available' => false,
                    'receipt_artifact_id' => null,
                ]],
            ],
        ])->save();

        $result = app(MeiPortalResultTranslator::class)->translate(
            new FiscalAdapterRequest(
                office: $office,
                client: $client,
                run: $run,
                systemCode: 'INTEGRA_MEI',
                serviceCode: 'DASN_SIMEI',
                operationCode: 'CONSULTAR',
                trigger: FiscalTrigger::Manual,
            ),
            $attempt->refresh(),
        );

        self::assertSame(FiscalCoverage::Partial, $result->coverage);
        self::assertSame(FiscalSituation::Pending, $result->situation);
        self::assertSame([2025], $result->normalized['pending_years']);
        self::assertTrue($result->normalized['declarations'][0]['pending']);
        self::assertSame('Original', $result->normalized['declarations'][0]['declaration_type']);
        self::assertSame('DASN_SIMEI_DECLARATION_PENDING', $result->findings[0]['code']);
        self::assertTrue($result->findings[0]['creates_pending']);
        self::assertSame(FiscalSituation::Pending->value, $result->findings[0]['situation']);
    }

    private function monitoringRun(Office $office, Client $client, string $service): FiscalMonitoringRun
    {
        return FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_MEI',
            'service_code' => $service,
            'operation_code' => 'CONSULTAR',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'translator-run:12345678',
        ]);
    }
}
