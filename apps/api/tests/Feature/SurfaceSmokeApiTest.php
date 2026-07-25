<?php

namespace Tests\Feature;

use App\Enums\OfficeRole;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SurfaceSmokeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_fiscal_cluster_smoke_does_not_500(): void
    {
        $this->actingAsOperator();

        $response = $this->getJson('/api/v1/fiscal/manual-consults');

        $this->assertLessThan(500, $response->status());
    }

    public function test_serpro_cluster_smoke_does_not_500(): void
    {
        $office = Office::factory()->create();
        $admin = User::factory()
            ->forOffice($office, OfficeRole::Operator)
            ->asPlatformAdmin($office->id)
            ->create();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/platform/serpro/configuration?environment=TRIAL');

        $this->assertLessThan(500, $response->status());
    }

    public function test_office_cluster_smoke_does_not_500(): void
    {
        $this->actingAsOperator();

        $response = $this->getJson('/api/v1/office/settings');

        $this->assertLessThan(500, $response->status());
    }

    public function test_clients_cluster_smoke_does_not_500(): void
    {
        $this->actingAsOperator();

        $response = $this->getJson('/api/v1/clients');

        $this->assertLessThan(500, $response->status());
    }

    public function test_monitoring_cluster_smoke_does_not_500(): void
    {
        $this->actingAsOperator();

        $response = $this->getJson('/api/v1/fiscal/monitoring/insights');

        $this->assertLessThan(500, $response->status());
    }

    public function test_work_cluster_smoke_does_not_500(): void
    {
        $this->actingAsOperator();

        $response = $this->getJson('/api/v1/work/processes');

        $this->assertLessThan(500, $response->status());
    }

    private function actingAsOperator(): User
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        Sanctum::actingAs($user);

        return $user;
    }
}
