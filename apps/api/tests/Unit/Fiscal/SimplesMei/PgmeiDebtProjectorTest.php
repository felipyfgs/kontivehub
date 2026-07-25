<?php

namespace Tests\Unit\Fiscal\SimplesMei;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Enums\PgmeiDebtState;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\PgmeiDebtProjection;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiDebtProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class PgmeiDebtProjectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_list_projects_no_active_debt_idempotently(): void
    {
        [$office, $client, $run] = $this->seedProjectorContext();
        $decoded = [
            'calendar_year' => 2024,
            'items' => [],
            'items_count' => 0,
            'total_cents' => 0,
            'digest' => hash('sha256', 'empty-2024'),
        ];

        $first = (new PgmeiDebtProjector)->projectValid($office, $client, $decoded, $run->id);
        $second = (new PgmeiDebtProjector)->projectValid($office, $client, $decoded, $run->id);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['observation']->id, $second['observation']->id);
        $this->assertSame(PgmeiDebtState::NoActiveDebt, $first['projection']->debt_state);
        $this->assertSame(0, (int) $first['projection']->items_count);

        $projection = PgmeiDebtProjection::query()
            ->withoutGlobalScopes()
            ->where('client_id', $client->id)
            ->where('calendar_year', 2024)
            ->sole();
        $this->assertSame(PgmeiDebtState::NoActiveDebt, $projection->debt_state);
    }

    public function test_items_project_has_active_debt(): void
    {
        [$office, $client, $run] = $this->seedProjectorContext();
        $decoded = [
            'calendar_year' => 2024,
            'items' => [[
                'periodo_apuracao' => '202401',
                'tributo' => 'DAS',
                'amount_cents' => 1250,
                'ente_federado' => 'RFB',
                'situacao_debito' => 'Em cobrança',
            ]],
            'items_count' => 1,
            'total_cents' => 1250,
            'digest' => hash('sha256', 'one-debt-2024'),
        ];

        $projected = (new PgmeiDebtProjector)->projectValid($office, $client, $decoded, $run->id);

        $this->assertSame(PgmeiDebtState::HasActiveDebt, $projected['projection']->debt_state);
        $this->assertSame(1, (int) $projected['projection']->items_count);
        $this->assertSame(1250, (int) $projected['projection']->total_cents);
    }

    public function test_cross_tenant_client_is_rejected(): void
    {
        [$office, , $run] = $this->seedProjectorContext();
        $other = Client::factory()->forOffice(Office::factory()->create())->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cliente não pertence ao escritório');

        (new PgmeiDebtProjector)->projectValid($office, $other, [
            'calendar_year' => 2024,
            'items' => [],
            'items_count' => 0,
            'total_cents' => 0,
            'digest' => hash('sha256', 'cross'),
        ], $run->id);
    }

    /** @return array{Office, Client, FiscalMonitoringRun} */
    private function seedProjectorContext(): array
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
            'idempotency_key' => 'pgmei-projector:'.fake()->uuid(),
            'status' => FiscalRunStatus::Running,
            'situation' => FiscalSituation::Processing,
            'coverage' => FiscalCoverage::Unknown,
            'mutability' => FiscalMutability::ReadOnly,
        ]);

        return [$office, $client, $run];
    }
}
