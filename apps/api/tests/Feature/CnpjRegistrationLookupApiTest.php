<?php

namespace Tests\Feature;

use App\DTO\Cnpj\DocumentMask;
use App\Enums\OfficeRole;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CnpjRegistrationLookupApiTest extends TestCase
{
    use RefreshDatabase;

    private const CNPJ = '27865757000102';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.cnpj_public_lookup.url' => 'https://publica.cnpj.ws/cnpj',
            'services.cnpj_serpro_consulta.enabled' => false,
            'services.cnpj_public_lookup.ccmei_enrichment' => false,
        ]);
    }

    public function test_lookup_maps_expanded_cartao_cnpj_fields_without_raw_cpf(): void
    {
        [$user] = $this->actor(OfficeRole::Operator);
        Sanctum::actingAs($user);

        Http::fake([
            'https://publica.cnpj.ws/cnpj/*' => Http::response($this->fixture('publica_cnpj_ws_27865757000102.json'), 200),
        ]);

        $response = $this->getJson('/api/v1/cnpj/'.self::CNPJ.'/lookup')
            ->assertOk()
            ->assertJsonPath('data.source', 'CNPJ_WS')
            ->assertJsonPath('data.client.legal_name', 'GLOBO COMUNICACAO E PARTICIPACOES S/A')
            ->assertJsonPath('data.establishment.main_cnae_code', '6021700');

        $data = $response->json('data');
        $this->assertNotEmpty($data['client']['capital_social'] ?? null);
        $secondaryCnaes = $data['establishment']['secondary_cnaes'] ?? [];
        $this->assertIsArray($secondaryCnaes);
        $this->assertGreaterThanOrEqual(26, count($secondaryCnaes));
        $this->assertNotEmpty($data['establishment']['state_registrations'] ?? []);

        $shareholders = $data['establishment']['shareholders'] ?? [];
        $this->assertIsArray($shareholders);
        $this->assertNotEmpty($shareholders);
        $uniqueNames = array_unique(array_map(
            static fn (array $row): string => mb_strtolower(trim((string) ($row['name'] ?? ''))),
            $shareholders,
        ));
        $this->assertLessThanOrEqual(6, count($uniqueNames));
        $this->assertCount(count($uniqueNames), $shareholders);

        foreach ($shareholders as $shareholder) {
            $doc = $shareholder['document_masked'] ?? null;
            if ($doc !== null) {
                $this->assertStringContainsString('*', $doc);
                $this->assertDoesNotMatchRegularExpression('/^\d{11}$/', preg_replace('/\D+/', '', $doc) ?? '');
            }
            $encoded = json_encode($shareholder, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('"cpf":', (string) $encoded);
        }

        $this->assertContains('CNPJ_WS', $data['sources_used']);
    }

    public function test_lookup_merges_serpro_qsa_when_enabled(): void
    {
        [$user] = $this->actor(OfficeRole::Operator);
        Sanctum::actingAs($user);

        config([
            'services.cnpj_serpro_consulta.enabled' => true,
            'services.cnpj_serpro_consulta.consumer_key' => 'key',
            'services.cnpj_serpro_consulta.consumer_secret' => 'secret',
            'services.cnpj_serpro_consulta.token_url' => 'https://gateway.apiserpro.serpro.gov.br/token',
            'services.cnpj_serpro_consulta.base_url' => 'https://gateway.apiserpro.serpro.gov.br/consulta-cnpj-df/v2',
            'services.cnpj_serpro_consulta.qsa_path' => '/qsa',
        ]);

        Http::fake([
            'https://publica.cnpj.ws/cnpj/*' => Http::response($this->fixture('publica_cnpj_ws_27865757000102.json'), 200),
            'https://gateway.apiserpro.serpro.gov.br/token' => Http::response([
                'access_token' => 'token-test',
                'expires_in' => 3600,
            ], 200),
            'https://gateway.apiserpro.serpro.gov.br/consulta-cnpj-df/v2/qsa/*' => Http::response(
                $this->fixture('serpro_consulta_qsa_sample.json'),
                200,
            ),
        ]);

        $this->getJson('/api/v1/cnpj/'.self::CNPJ.'/lookup')
            ->assertOk()
            ->assertJsonPath('data.source', 'SERPRO_CONSULTA')
            ->assertJsonPath('data.establishment.public_email', 'contato@example.com')
            ->assertJsonPath('data.sources_used.0', 'CNPJ_WS');
    }

    public function test_lookup_falls_back_to_cnpj_ws_when_serpro_fails(): void
    {
        [$user] = $this->actor(OfficeRole::Operator);
        Sanctum::actingAs($user);

        config([
            'services.cnpj_serpro_consulta.enabled' => true,
            'services.cnpj_serpro_consulta.consumer_key' => 'key',
            'services.cnpj_serpro_consulta.consumer_secret' => 'secret',
            'services.cnpj_serpro_consulta.token_url' => 'https://gateway.apiserpro.serpro.gov.br/token',
            'services.cnpj_serpro_consulta.base_url' => 'https://gateway.apiserpro.serpro.gov.br/consulta-cnpj-df/v2',
            'services.cnpj_serpro_consulta.qsa_path' => '/qsa',
        ]);

        Http::fake([
            'https://publica.cnpj.ws/cnpj/*' => Http::response($this->fixture('publica_cnpj_ws_27865757000102.json'), 200),
            'https://gateway.apiserpro.serpro.gov.br/token' => Http::response(['error' => 'denied'], 401),
            'https://gateway.apiserpro.serpro.gov.br/consulta-cnpj-df/v2/qsa/*' => Http::response(['message' => 'down'], 503),
        ]);

        $fallback = $this->getJson('/api/v1/cnpj/'.self::CNPJ.'/lookup')
            ->assertOk()
            ->json('data');

        $this->assertSame('CNPJ_WS', $fallback['source']);
        $this->assertContains('CNPJ_WS', $fallback['sources_used']);
        $this->assertNotContains('SERPRO_CONSULTA', $fallback['sources_used']);
    }

    public function test_store_persists_expanded_snapshot_from_lookup_cache(): void
    {
        [$user, $office] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($user);

        Http::fake([
            'https://publica.cnpj.ws/cnpj/*' => Http::response($this->fixture('publica_cnpj_ws_27865757000102.json'), 200),
        ]);

        $lookup = $this->getJson('/api/v1/cnpj/'.self::CNPJ.'/lookup')->assertOk()->json('data');

        $payload = [
            'legal_name' => $lookup['client']['legal_name'],
            'cnpj' => self::CNPJ,
            'trade_name' => $lookup['establishment']['trade_name'],
            'registration_status' => $lookup['establishment']['registration_status'],
            'registration_status_at' => $lookup['establishment']['registration_status_at'],
            'activity_started_at' => $lookup['establishment']['activity_started_at'],
            'main_cnae_code' => $lookup['establishment']['main_cnae_code'],
            'main_cnae_name' => $lookup['establishment']['main_cnae_name'],
            'public_email' => $lookup['establishment']['public_email'],
            'public_phone' => $lookup['establishment']['public_phone'],
            'capital_social' => $lookup['client']['capital_social'],
            'secondary_cnaes' => $lookup['establishment']['secondary_cnaes'],
            'state_registrations' => $lookup['establishment']['state_registrations'],
            'shareholders' => $lookup['establishment']['shareholders'],
            'address' => $lookup['establishment']['address'],
            'legal_nature_code' => $lookup['client']['legal_nature_code'],
            'legal_nature_name' => $lookup['client']['legal_nature_name'],
            'company_size_code' => $lookup['client']['company_size_code'],
            'company_size_name' => $lookup['client']['company_size_name'],
        ];

        $created = $this->postJson('/api/v1/clients', $payload)
            ->assertCreated()
            ->json('data.client');

        $client = Client::query()->where('office_id', $office->id)->findOrFail($created['id']);
        $this->assertNotNull($client->capital_social);

        $est = Establishment::query()->where('client_id', $client->id)->firstOrFail();
        $this->assertNotEmpty($est->secondary_cnaes);
        $this->assertNotEmpty($est->shareholders);
        foreach ($est->shareholders as $shareholder) {
            $doc = $shareholder['document_masked'] ?? null;
            if (is_string($doc) && $doc !== '') {
                $this->assertStringContainsString('*', $doc);
            }
        }
    }

    public function test_refresh_registration_updates_rfb_without_touching_internal_fields(): void
    {
        [$user, $office] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($user);

        $client = Client::factory()->forOffice($office)->create([
            'legal_name' => 'ANTIGA RAZAO',
            'display_name' => 'Apelido Interno',
            'tax_regime' => 'SIMPLES_NACIONAL',
            'notes' => 'nota interna',
            'root_cnpj' => '27865757',
        ]);
        Establishment::factory()->forClient($client)->create([
            'cnpj' => self::CNPJ,
            'trade_name' => 'VELHO',
            'is_matrix' => true,
        ]);

        Http::fake([
            'https://publica.cnpj.ws/cnpj/*' => Http::response($this->fixture('publica_cnpj_ws_27865757000102.json'), 200),
        ]);

        $response = $this->postJson("/api/v1/clients/{$client->id}/refresh-registration")
            ->assertOk()
            ->json('data');

        $this->assertSame('Apelido Interno', $response['display_name']);
        $this->assertSame('nota interna', $response['notes']);
        $this->assertSame('SIMPLES_NACIONAL', $response['tax_regime']);
        $this->assertNotSame('ANTIGA RAZAO', $response['legal_name']);
        $this->assertNotEmpty($response['establishments'][0]['shareholders'] ?? []);
    }

    public function test_refresh_registration_applies_confirmed_lookup_snapshot(): void
    {
        [$user, $office] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($user);

        $client = Client::factory()->forOffice($office)->create([
            'legal_name' => 'ANTIGA RAZAO',
            'display_name' => 'Apelido Interno',
            'tax_regime' => 'SIMPLES_NACIONAL',
            'notes' => 'nota interna',
            'root_cnpj' => '27865757',
        ]);
        Establishment::factory()->forClient($client)->create([
            'cnpj' => self::CNPJ,
            'trade_name' => 'VELHO',
            'is_matrix' => true,
        ]);

        Http::fake([
            'https://publica.cnpj.ws/cnpj/*' => Http::response($this->fixture('publica_cnpj_ws_27865757000102.json'), 200),
        ]);

        $lookup = $this->getJson('/api/v1/cnpj/'.self::CNPJ.'/lookup')
            ->assertOk()
            ->json('data');

        $lookup['client']['legal_name'] = 'RAZAO CONFIRMADA PELO USUARIO';

        $response = $this->postJson("/api/v1/clients/{$client->id}/refresh-registration", [
            'lookup' => $lookup,
        ])
            ->assertOk()
            ->json('data');

        $this->assertSame('RAZAO CONFIRMADA PELO USUARIO', $response['legal_name']);
        $this->assertSame('Apelido Interno', $response['display_name']);
        $this->assertSame('nota interna', $response['notes']);
        $this->assertSame('SIMPLES_NACIONAL', $response['tax_regime']);
    }

    public function test_refresh_registration_rejects_confirmed_snapshot_with_mismatched_cnpj(): void
    {
        [$user, $office] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($user);

        $client = Client::factory()->forOffice($office)->create([
            'root_cnpj' => '27865757',
        ]);
        Establishment::factory()->forClient($client)->create([
            'cnpj' => self::CNPJ,
            'is_matrix' => true,
        ]);

        Http::fake([
            'https://publica.cnpj.ws/cnpj/*' => Http::response($this->fixture('publica_cnpj_ws_27865757000102.json'), 200),
        ]);

        $lookup = $this->getJson('/api/v1/cnpj/'.self::CNPJ.'/lookup')
            ->assertOk()
            ->json('data');
        $lookup['establishment']['cnpj'] = '11222333000181';

        $this->postJson("/api/v1/clients/{$client->id}/refresh-registration", [
            'lookup' => $lookup,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['lookup']);
    }

    public function test_document_mask_never_keeps_plain_cpf(): void
    {
        $this->assertSame('***456789**', DocumentMask::ensureMasked('12345678901'));
        $this->assertSame('***050187**', DocumentMask::ensureMasked('***050187**'));
        $this->assertNull(DocumentMask::ensureMasked(null));
    }

    /** @return array{User, Office} */
    private function actor(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, $role)->create();

        return [$user, $office];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $path = base_path('tests/Fixtures/Cnpj/'.$name);
        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
