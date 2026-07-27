<?php

namespace Tests\Concerns;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use App\Enums\FiscalProfile;
use App\Enums\FiscalSituation;
use App\Enums\TaxObligationApplicability;
use App\Enums\TaxRegimeCode;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\FiscalModuleControl;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Laravel\Sanctum\Sanctum;

/**
 * Seed reutilizável da carteira Simples Nacional (PGDAS-D) para Features HTTP.
 *
 * @phpstan-type PortfolioSeed array{
 *     tenant: Tenant,
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
    protected function seedSimplesNacionalPortfolio(?Tenant $tenant = null): array
    {
        // Container local pode herdar FISCAL_PROFILE=production; Features HTTP usam Dev.
        config()->set('fiscal.profile', FiscalProfile::Dev->value);

        $tenant ??= Tenant::factory()->create();

        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();

        $sn = Client::factory()->for($tenant)->create([
            'legal_name' => 'Cliente SN Portfolio',
            'is_active' => true,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);
        $mei = Client::factory()->for($tenant)->create([
            'legal_name' => 'Cliente MEI Fora Escopo',
            'is_active' => true,
            'tax_regime' => TaxRegimeCode::Mei->value,
        ]);
        $other = Client::factory()->for($tenant)->create([
            'legal_name' => 'Cliente Outro Regime',
            'is_active' => true,
            'tax_regime' => TaxRegimeCode::LucroPresumido->value,
        ]);

        ClientContact::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $sn->id,
            'email' => 'sn-ops@example.com',
            'is_active' => true,
            'receives_alerts' => true,
        ]);

        return [
            'tenant' => $tenant,
            'operator' => $operator,
            'viewer' => $viewer,
            'sn' => $sn,
            'mei' => $mei,
            'other' => $other,
        ];
    }

    protected function actingAsTenantUser(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }

    protected function restrictSimplesMeiModule(Tenant $tenant, ?User $actor = null): void
    {
        FiscalModuleControl::query()->create([
            'module_key' => FiscalControlModule::SimplesMei,
            'scope' => FiscalModuleControlScope::Tenant,
            'tenant_id' => $tenant->id,
            'restricted' => true,
            'reason' => 'Test restriction',
            'updated_by_user_id' => $actor?->id,
        ]);
    }

    protected function seedPgdasProjection(
        Tenant $tenant,
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
            'tenant_id' => $tenant->id,
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
