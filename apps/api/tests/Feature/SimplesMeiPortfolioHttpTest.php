<?php

namespace Tests\Feature;

use App\Enums\FiscalSituation;
use App\Enums\TaxRegimeCode;
use App\Models\Client;
use App\Models\ClientCommunicationDispatch;
use App\Models\Office;
use App\Models\User;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdCommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsSimplesNacionalPortfolio;
use Tests\TestCase;

class SimplesMeiPortfolioHttpTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSimplesNacionalPortfolio;

    public function test_overview_and_clients_list_only_simples_nacional(): void
    {
        $seed = $this->seedSimplesNacionalPortfolio();
        $this->actingAsOfficeUser($seed['operator']);

        $this->getJson('/api/v1/fiscal/modules/simples_mei/overview?submodule=PGDASD')
            ->assertOk()
            ->assertJsonPath('data.total_clients', 1);

        $clients = $this->getJson('/api/v1/fiscal/modules/simples_mei/clients?submodule=PGDASD&per_page=50')
            ->assertOk()
            ->json('data');

        $ids = collect($clients)->pluck('client_id')->all();
        $this->assertContains($seed['sn']->id, $ids);
        $this->assertNotContains($seed['mei']->id, $ids);
        $this->assertNotContains($seed['other']->id, $ids);
    }

    public function test_viewer_can_read_portfolio(): void
    {
        $seed = $this->seedSimplesNacionalPortfolio();
        $this->actingAsOfficeUser($seed['viewer']);

        $this->getJson('/api/v1/fiscal/modules/simples_mei/overview?submodule=PGDASD')
            ->assertOk()
            ->assertJsonPath('data.total_clients', 1);

        $this->getJson('/api/v1/fiscal/modules/simples_mei/clients?submodule=PGDASD')
            ->assertOk();
    }

    public function test_restricted_module_returns_403(): void
    {
        $seed = $this->seedSimplesNacionalPortfolio();
        $this->restrictSimplesMeiModule($seed['office'], $seed['operator']);
        $this->actingAsOfficeUser($seed['operator']);

        $this->getJson('/api/v1/fiscal/modules/simples_mei/overview?submodule=PGDASD')
            ->assertForbidden();
        $this->getJson('/api/v1/fiscal/modules/simples_mei/clients?submodule=PGDASD')
            ->assertForbidden();
    }

    public function test_user_without_office_role_is_forbidden(): void
    {
        $office = Office::factory()->create();
        $orphan = User::factory()->create();
        $orphan->forceFill(['selected_office_id' => $office->id])->saveQuietly();
        Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);

        $this->actingAsOfficeUser($orphan);

        $this->getJson('/api/v1/fiscal/modules/simples_mei/overview?submodule=PGDASD')
            ->assertForbidden();
    }

    public function test_rejects_client_supplied_office_id(): void
    {
        $seed = $this->seedSimplesNacionalPortfolio();
        $this->actingAsOfficeUser($seed['operator']);

        $this->getJson('/api/v1/fiscal/modules/simples_mei/clients?submodule=PGDASD&office_id='.$seed['office']->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'CLIENT_OFFICE_ID_REJECTED');
    }

    public function test_isolates_other_office_clients(): void
    {
        $seed = $this->seedSimplesNacionalPortfolio();
        $otherOffice = Office::factory()->create();
        Client::factory()->for($otherOffice)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);
        $this->actingAsOfficeUser($seed['operator']);

        $this->getJson('/api/v1/fiscal/modules/simples_mei/clients?submodule=PGDASD&per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_filters_by_client_id_situation_and_send_status(): void
    {
        $seed = $this->seedSimplesNacionalPortfolio();
        $second = Client::factory()->for($seed['office'])->create([
            'legal_name' => 'Segundo SN',
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);

        $this->seedPgdasProjection($seed['office'], $seed['sn'], '2026-06', FiscalSituation::Pending);
        $this->seedPgdasProjection($seed['office'], $second, '2026-05', FiscalSituation::UpToDate);

        ClientCommunicationDispatch::query()->create([
            'office_id' => $seed['office']->id,
            'client_id' => $seed['sn']->id,
            'module_key' => PgdasdCommunicationService::MODULE,
            'submodule_key' => PgdasdCommunicationService::SUBMODULE,
            'channel' => 'EMAIL',
            'status' => 'QUEUED',
            'recipient_masked' => 'a***@example.com',
            'recipient_hash' => hash('sha256', 'a@example.com'),
            'idempotency_key' => 'portfolio-http-send-'.$seed['sn']->id,
            'queued_at' => now(),
        ]);

        $this->actingAsOfficeUser($seed['operator']);

        $byClient = $this->getJson('/api/v1/fiscal/modules/simples_mei/clients?submodule=PGDASD&client_id='.$seed['sn']->id)
            ->assertOk()
            ->json('data');
        $this->assertSame([$seed['sn']->id], collect($byClient)->pluck('client_id')->all());

        $bySituation = $this->getJson('/api/v1/fiscal/modules/simples_mei/clients?submodule=PGDASD&situation=PENDING')
            ->assertOk()
            ->json('data');
        $situationIds = collect($bySituation)->pluck('client_id')->all();
        $this->assertContains($seed['sn']->id, $situationIds);
        $this->assertNotContains($second->id, $situationIds);

        $byQ = $this->getJson('/api/v1/fiscal/modules/simples_mei/clients?submodule=PGDASD&q=Segundo')
            ->assertOk()
            ->json('data');
        $this->assertSame([$second->id], collect($byQ)->pluck('client_id')->all());

        $sent = $this->getJson('/api/v1/fiscal/modules/simples_mei/clients?submodule=PGDASD&send_status=sent')
            ->assertOk()
            ->json('data');
        $this->assertSame([$seed['sn']->id], collect($sent)->pluck('client_id')->all());

        $notSent = $this->getJson('/api/v1/fiscal/modules/simples_mei/clients?submodule=PGDASD&send_status=not_sent')
            ->assertOk()
            ->json('data');
        $this->assertSame([$second->id], collect($notSent)->pluck('client_id')->all());
    }

    public function test_sort_and_pagination(): void
    {
        $seed = $this->seedSimplesNacionalPortfolio();
        Client::factory()->for($seed['office'])->create([
            'legal_name' => 'AAA Primeiro',
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);
        Client::factory()->for($seed['office'])->create([
            'legal_name' => 'ZZZ Ultimo',
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);
        $this->actingAsOfficeUser($seed['operator']);

        $asc = $this->getJson('/api/v1/fiscal/modules/simples_mei/clients?submodule=PGDASD&sort=legal_name&sort_direction=asc&per_page=2&page=1')
            ->assertOk();
        $this->assertCount(2, $asc->json('data'));
        $this->assertSame(3, $asc->json('meta.total'));
        $this->assertSame('AAA Primeiro', $asc->json('data.0.legal_name'));

        $page2 = $this->getJson('/api/v1/fiscal/modules/simples_mei/clients?submodule=PGDASD&sort=legal_name&sort_direction=asc&per_page=2&page=2')
            ->assertOk();
        $this->assertCount(1, $page2->json('data'));
    }
}
