<?php

namespace Tests\Feature;

use App\Enums\SerproEligibilityCode;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Office;
use App\Services\Integra\IntegraEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegraEligibilityAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pgmei_evaluate_includes_authorization_missing_for_draft(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();

        $result = app(IntegraEligibilityService::class)->evaluate(
            office: $office,
            client: $client,
            solutionCode: 'PGMEI',
            serviceCode: 'DIVIDAATIVA24',
            operationCode: '1.0',
            environment: SerproEnvironment::Trial,
        );

        $codes = array_map(
            static fn (SerproEligibilityCode $code): string => $code->value,
            $result->codes,
        );

        $this->assertContains(
            SerproEligibilityCode::AuthorizationMissing->value,
            $codes,
        );
    }
}
