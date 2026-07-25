<?php

namespace Tests\Feature;

use App\Enums\OfficeRole;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeclarationCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_office_viewer_can_read_the_sanitized_declaration_catalog(): void
    {
        $office = Office::factory()->create();
        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();
        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/v1/fiscal/declarations/catalog')
            ->assertOk()
            ->assertJsonPath('data.integration_coverage.obligations.0.code', 'PGDAS')
            ->assertJsonPath('data.integration_coverage.obligations.2.code', 'DASN_SIMEI')
            ->assertJsonPath('data.integration_coverage.obligations.2.coverage', 'INVENTORIED')
            ->assertJsonPath('data.integration_coverage.obligations.5.source_kind', 'EXTERNAL')
            ->assertJsonPath('data.integration_coverage.obligations.6.coverage', 'UNSUPPORTED')
            ->assertJsonPath('data.operation_catalog.counts.total', 33)
            ->assertJsonPath('data.operation_catalog.counts.production', 23)
            ->assertJsonPath('data.operation_catalog.counts.prospection', 10)
            ->assertJsonCount(33, 'data.operation_catalog.operations')
            ->assertJsonMissingPath('data.integration_coverage.obligations.0.operation_key')
            ->assertJsonMissingPath('data.operation_catalog.operations.0.operation_key')
            ->assertJsonMissingPath('data.operation_catalog.operations.0.id_sistema')
            ->assertJsonMissingPath('data.integration_coverage.obligations.0.office_id');

        $json = json_encode($response->json(), JSON_THROW_ON_ERROR);
        foreach (['id_sistema', 'id_servico', 'request_schema', 'response_schema'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_declaration_catalog_requires_an_office_membership(): void
    {
        $this->getJson('/api/v1/fiscal/declarations/catalog')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/fiscal/declarations/catalog')->assertForbidden();
    }
}
