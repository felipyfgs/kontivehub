<?php

namespace Tests\Concerns;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use App\Enums\FiscalProfile;
use App\Enums\FiscalSituation;
use App\Enums\OfficeRole;
use App\Enums\TaxObligationApplicability;
use App\Enums\TaxRegimeCode;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\FiscalModuleControl;
use App\Models\Office;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Models\User;
use App\Support\CurrentOffice;
use Laravel\Sanctum\Sanctum;

/**
 * Seed reutilizável da carteira Simples Nacional (PGDAS-D) para Features HTTP.
 *
 * @phpstan-type PortfolioSeed array{
 *     office: Office,
 *     operator: User,
 *     viewer: User,
 *     sn: Client,
 *     mei: Client,
 *     other: Client
 * }
 */
trait SeedsSimplesNacionalPortfolio
{
    /**
     * @return PortfolioSeed
     */
    protected function seedSimplesNacionalPortfolio(?Office $office = null): array
    {
        // Container local pode herdar FISCAL_PROFILE=production; Features HTTP usam Dev.
        config()->set('fiscal.profile', FiscalProfile::Dev->value);

        $office ??= Office::factory()->create();

        $operator = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();

        $sn = Client::factory()->for($office)->create([
            'legal_name' => 'Cliente SN Portfolio',
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);
        $mei = Client::factory()->for($office)->create([
            'legal_name' => 'Cliente MEI Fora Escopo',
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::Mei->value,
        ]);
        $other = Client::factory()->for($office)->create([
            'legal_name' => 'Cliente Outro Regime',
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::LucroPresumido->value,
        ]);

        ClientContact::factory()->create([
            'office_id' => $office->id,
            'client_id' => $sn->id,
            'email' => 'sn-ops@example.com',
            'is_active' => true,
            'receives_alerts' => true,
        ]);

        return [
            'office' => $office,
            'operator' => $operator,
            'viewer' => $viewer,
            'sn' => $sn,
            'mei' => $mei,
            'other' => $other,
        ];
    }

    protected function actingAsOfficeUser(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();
    }

    protected function restrictSimplesMeiModule(Office $office, ?User $actor = null): void
    {
        FiscalModuleControl::query()->create([
            'module_key' => FiscalControlModule::SimplesMei,
            'scope' => FiscalModuleControlScope::Office,
            'office_id' => $office->id,
            'restricted' => true,
            'reason' => 'Test restriction',
            'updated_by_user_id' => $actor?->id,
        ]);
    }

    protected function seedPgdasProjection(
        Office $office,
        Client $client,
        string $periodKey = '2026-06',
        FiscalSituation $situation = FiscalSituation::Pending,
    ): TaxObligationProjection {
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

        $month = (int) substr($periodKey, 5, 2);

        return TaxObligationProjection::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'obligation_definition_id' => $def->id,
            'period_key' => $periodKey,
            'period_year' => (int) substr($periodKey, 0, 4),
            'period_month' => $month,
            'is_open' => true,
            'situation' => $situation,
            'delivery_status' => $situation,
            'applicability' => TaxObligationApplicability::Applicable,
        ]);
    }
}
