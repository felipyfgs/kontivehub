<?php

namespace Tests\Unit\Fiscal\SimplesMei\Pgdasd;

use App\Enums\FiscalProfile;
use App\Enums\FiscalSituation;
use App\Enums\PgdasdOperationAmountSource;
use App\Enums\PgdasdOperationKind;
use App\Enums\TaxObligationApplicability;
use App\Models\Client;
use App\Models\Office;
use App\Models\PgdasdOperation;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdOperationAmountService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PgdasdOperationAmountServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function apply_from_gerar_das_normalized_persists_cents(): void
    {
        config()->set('fiscal.profile', FiscalProfile::Dev->value);

        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        $def = TaxObligationDefinition::query()->firstOrCreate(
            ['code' => 'PGDAS_D'],
            [
                'name' => 'PGDAS-D',
                'system_code' => 'INTEGRA_SN',
                'service_code' => 'PGDASD',
                'is_active' => true,
                'sort_order' => 10,
            ],
        );
        $projection = TaxObligationProjection::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'obligation_definition_id' => $def->id,
            'period_key' => '2026-06',
            'period_year' => 2026,
            'period_month' => 6,
            'is_open' => true,
            'situation' => FiscalSituation::Pending,
            'delivery_status' => FiscalSituation::Pending,
            'applicability' => TaxObligationApplicability::Applicable,
        ]);

        $dasNumber = '07202619183811980';
        PgdasdOperation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'projection_id' => $projection->id,
            'kind' => PgdasdOperationKind::Das,
            'period_key' => '2026-06',
            'logical_key' => 'das:2026-06:'.$dasNumber,
            'raw_operation_type' => 'DAS',
            'normalized_operation_type' => 'DAS',
            'das_number' => $dasNumber,
            'payment_located' => false,
            'first_seen_at' => CarbonImmutable::now(),
            'last_seen_at' => CarbonImmutable::now(),
        ]);

        $ok = app(PgdasdOperationAmountService::class)->applyFromGerarDasNormalized(
            $office,
            (int) $client->id,
            [
                'document_number' => $dasNumber,
                'amount' => 199.9,
            ],
        );

        $this->assertTrue($ok);
        $operation = PgdasdOperation::query()->withoutGlobalScopes()->where('das_number', $dasNumber)->first();
        $this->assertSame(19990, $operation?->amount_cents);
        $this->assertSame(PgdasdOperationAmountSource::GerarDas->value, $operation?->amount_source);
    }
}
