<?php

namespace Tests\Feature;

use App\Enums\OfficeRole;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\FiscalMutationOperation;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DeclarationOperationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_controlled_mutation_preflight_is_fail_closed_and_persists_only_encrypted_payload(): void
    {
        [$office, $client, $admin] = $this->tenant();
        Sanctum::actingAs($admin);

        $response = $this->postJson(
            '/api/v1/fiscal/declarations/operations/decl_pgdas_gerar_das/preflight',
            $this->preflightPayload($client, 'decl-preflight-1'),
        );

        $response->assertStatus(422)
            ->assertJsonPath('data.action_id', 'decl_pgdas_gerar_das')
            ->assertJsonPath('data.eligible', false)
            ->assertJsonMissingPath('data.operation')
            ->assertJsonMissingPath('data.eligibility.context')
            ->assertJsonMissingPath('data.office_id');

        $operation = FiscalMutationOperation::query()->firstOrFail();
        self::assertSame($office->id, $operation->office_id);
        self::assertSame('pgdasd.gerardas', $operation->provider_operation_key);
        self::assertSame(['periodoApuracao' => '202607'], $operation->request_payload_encrypted);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $operation->request_payload_digest);

        $raw = (string) DB::table('fiscal_mutation_operations')
            ->whereKey($operation->id)
            ->value('request_payload_encrypted');
        self::assertStringNotContainsString('periodoApuracao', $raw);
        self::assertStringNotContainsString('202607', $raw);

        $json = json_encode($response->json(), JSON_THROW_ON_ERROR);
        foreach (['id_sistema', 'id_servico', 'operation_key', 'solution_code', 'service_code', 'operation_code'] as $technical) {
            self::assertStringNotContainsString($technical, $json);
        }
    }

    public function test_idempotency_key_cannot_be_reused_with_a_changed_payload(): void
    {
        [, $client, $admin] = $this->tenant();
        Sanctum::actingAs($admin);
        $uri = '/api/v1/fiscal/declarations/operations/decl_pgdas_gerar_das/preflight';

        $this->postJson($uri, $this->preflightPayload($client, 'decl-same-key'))
            ->assertStatus(422);

        $changed = $this->preflightPayload($client, 'decl-same-key');
        $changed['params']['period_key'] = '2026-08';
        $this->postJson($uri, $changed)
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');

        self::assertSame(1, FiscalMutationOperation::query()->count());
    }

    public function test_prospection_unknown_and_technical_payloads_are_rejected_without_egress(): void
    {
        [, $client, $admin] = $this->tenant();
        Sanctum::actingAs($admin);

        $this->postJson(
            '/api/v1/fiscal/declarations/operations/decl_dasn_entregar/preflight',
            [
                'client_id' => $client->id,
                'idempotency_key' => 'decl-prospection',
                'params' => [
                    'calendar_year' => 2026,
                    'business_payload' => ['declaration' => []],
                ],
            ],
        )->assertStatus(422)->assertJsonPath('code', 'OPERATION_NOT_PRODUCTION');

        $this->postJson(
            '/api/v1/fiscal/declarations/operations/decl_unknown/preflight',
            $this->preflightPayload($client, 'decl-unknown'),
        )->assertNotFound()->assertJsonPath('code', 'OPERATION_NOT_FOUND');

        $withCoordinates = $this->preflightPayload($client, 'decl-coordinates');
        $withCoordinates['solution_code'] = 'PGDASD';
        $this->postJson(
            '/api/v1/fiscal/declarations/operations/decl_pgdas_gerar_das/preflight',
            $withCoordinates,
        )->assertUnprocessable()->assertJsonValidationErrors('request');

        $nestedTechnical = $this->preflightPayload($client, 'decl-nested-coordinates');
        $nestedTechnical['params']['operation_key'] = 'pgdasd.gerardas';
        $this->postJson(
            '/api/v1/fiscal/declarations/operations/decl_pgdas_gerar_das/preflight',
            $nestedTechnical,
        )->assertUnprocessable()->assertJsonValidationErrors('params.operation_key');

        self::assertSame(0, FiscalMutationOperation::query()->count());
    }

    public function test_client_from_another_office_is_not_addressable(): void
    {
        [, , $admin] = $this->tenant();
        $foreignOffice = Office::factory()->create();
        $foreignClient = Client::factory()->forOffice($foreignOffice)->create();
        Establishment::factory()->forClient($foreignClient)->create();
        Sanctum::actingAs($admin);

        $this->postJson(
            '/api/v1/fiscal/declarations/operations/decl_pgdas_gerar_das/preflight',
            $this->preflightPayload($foreignClient, 'decl-cross-tenant'),
        )->assertNotFound();

        self::assertSame(0, FiscalMutationOperation::query()->count());
    }

    public function test_prospection_read_is_visible_but_not_executable(): void
    {
        [, $client, $admin] = $this->tenant();
        Sanctum::actingAs($admin);

        $this->postJson(
            '/api/v1/fiscal/declarations/operations/decl_dasn_consultar/read',
            [
                'client_id' => $client->id,
                'confirmed' => true,
                'params' => ['calendar_year' => 2026],
            ],
        )->assertUnprocessable()->assertJsonPath('code', 'OPERATION_NOT_PRODUCTION');
    }

    /** @return array{Office, Client, User} */
    private function tenant(): array
    {
        $office = Office::factory()->create();
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        $client = Client::factory()->forOffice($office)->create();
        Establishment::factory()->forClient($client)->create();

        return [$office, $client, $admin];
    }

    /** @return array<string, mixed> */
    private function preflightPayload(Client $client, string $idempotency): array
    {
        return [
            'client_id' => $client->id,
            'idempotency_key' => $idempotency,
            'params' => ['period_key' => '2026-07'],
        ];
    }
}
