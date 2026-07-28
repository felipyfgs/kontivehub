<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CredentialStatus;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\EsocialEventEvidence;
use App\Models\FgtsCompetenceStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class FgtsEsocialApiContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('fgts_esocial.driver', 'official_bx');
        config()->set('fgts_esocial.environment', 'restricted');
        config()->set('fgts_esocial.production_egress_enabled', false);
        config()->set('fgts_esocial.kill_switch', false);
        config()->set('fgts_esocial.official_bx.daily_access_limit', 10);
    }

    public function test_read_endpoints_preserve_resources_and_pagination_contracts(): void
    {
        [$tenant, $client] = $this->actingTenant();
        $this->credential($client);
        [$status, $evidence] = $this->records($tenant, $client);

        $this->getJson('/api/v1/fiscal/fgts/coverage')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'module',
                    'coverage',
                    'coverage_label',
                    'source',
                    'accepted_events',
                    'independent_states',
                    'limitations',
                    'declares_fgts_digital_debt',
                    'scraping_allowed',
                    'portal_fallback',
                    'official_limits',
                ],
            ])
            ->assertJsonPath('data.declares_fgts_digital_debt', false);

        $this->getJson('/api/v1/fiscal/fgts/readiness?client_id='.$client->id)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'ready',
                    'driver',
                    'environment',
                    'blockers',
                    'daily_limit',
                    'locally_consumed',
                    'locally_remaining',
                    'credential' => ['fingerprint_suffix', 'expires_at'],
                    'blocked_days',
                    'quota_scope',
                ],
            ])
            ->assertJsonMissingPath('data.credential.vault_object_id');

        $this->getJson('/api/v1/fiscal/fgts/competences?per_page=1')
            ->assertOk()
            ->assertJsonStructure($this->pageStructure([
                'id',
                'tenant_id',
                'client_id',
                'establishment_id',
                'competence_period_key',
                'closure_status',
                'closure_status_label',
                'totalization_status',
                'totalization_status_label',
                'guide_status',
                'guide_status_label',
                'payment_status',
                'payment_status_label',
                'coverage',
                'situation',
                'limitations',
                'partial_coverage',
                'declares_fgts_digital_debt',
                'run_id',
                'snapshot_id',
                'is_quarantined',
                'quarantine_reason',
            ]))
            ->assertJsonPath('data.0.id', $status->id)
            ->assertJsonPath('data.0.declares_fgts_digital_debt', false);

        $this->getJson('/api/v1/fiscal/fgts/competences/'.$status->id)
            ->assertOk()
            ->assertJsonPath('data.id', $status->id)
            ->assertJsonPath('events.0.id', $evidence->id)
            ->assertJsonPath('coverage.declares_fgts_digital_debt', false);

        $this->getJson('/api/v1/fiscal/fgts/events?per_page=1')
            ->assertOk()
            ->assertJsonStructure([
                ...$this->pageStructure([
                    'id',
                    'tenant_id',
                    'client_id',
                    'establishment_id',
                    'run_id',
                    'competence_period_key',
                    'event_code',
                    'event_label',
                    'content_sha256',
                    'byte_size',
                    'source',
                    'observed_at',
                    'is_totalizer',
                    'is_closure',
                    'is_quarantined',
                    'quarantine_reason',
                ]),
                'coverage' => [
                    'partial',
                    'limitations',
                    'declares_fgts_digital_debt',
                ],
            ])
            ->assertJsonPath('data.0.id', $evidence->id)
            ->assertJsonPath('coverage.declares_fgts_digital_debt', false);
    }

    public function test_reads_are_tenant_scoped_and_viewer_may_not_sync(): void
    {
        [$tenant, $client] = $this->actingTenant();
        [$status] = $this->records($tenant, $client);
        $foreignTenant = Tenant::factory()->create();
        $foreignClient = Client::factory()->forTenant($foreignTenant)->create();
        [$foreignStatus] = $this->records($foreignTenant, $foreignClient, '2026-05');

        $this->getJson('/api/v1/fiscal/fgts/competences?client_id='.$foreignClient->id)
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->getJson('/api/v1/fiscal/fgts/events?client_id='.$foreignClient->id)
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->getJson('/api/v1/fiscal/fgts/competences/'.$foreignStatus->id)
            ->assertNotFound();
        $this->getJson('/api/v1/fiscal/fgts/competences/'.$status->id)
            ->assertOk();

        Sanctum::actingAs(User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create());
        $this->getJson('/api/v1/fiscal/fgts/coverage')->assertOk();
        $this->postJson('/api/v1/fiscal/fgts/sync', [
            'client_id' => $client->id,
            'competence_period_key' => '2026-06',
        ])->assertForbidden();
    }

    public function test_all_endpoints_reject_client_supplied_tenant_scope(): void
    {
        [$tenant, $client] = $this->actingTenant();
        [$status] = $this->records($tenant, $client);
        $foreignTenant = Tenant::factory()->create();

        $getEndpoints = [
            '/api/v1/fiscal/fgts/coverage',
            '/api/v1/fiscal/fgts/readiness?client_id='.$client->id,
            '/api/v1/fiscal/fgts/competences',
            '/api/v1/fiscal/fgts/competences/'.$status->id,
            '/api/v1/fiscal/fgts/events',
        ];

        foreach ($getEndpoints as $endpoint) {
            $separator = str_contains($endpoint, '?') ? '&' : '?';
            $this->getJson($endpoint.$separator.'tenant_id='.$foreignTenant->id)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('tenant_id');
        }

        $payload = [
            'tenant_id' => $foreignTenant->id,
            'client_id' => $client->id,
            'competence_period_key' => '2026-06',
        ];
        $this->postJson('/api/v1/fiscal/fgts/sync', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
        $this->postJson('/api/v1/fiscal/fgts/sync-now', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
    }

    /** @return array{Tenant, Client} */
    private function actingTenant(): array
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        Sanctum::actingAs(User::factory()
            ->forTenant($tenant, TenantRole::TenantAdmin)
            ->create());

        return [$tenant, $client];
    }

    private function credential(Client $client): void
    {
        ClientCredential::query()->withoutGlobalScopes()->create([
            'tenant_id' => $client->tenant_id,
            'client_id' => $client->id,
            'status' => CredentialStatus::Active,
            'subject_name' => 'Certificado de teste',
            'holder_cnpj' => $client->root_cnpj.'000100',
            'fingerprint_sha256' => str_repeat('a', 64),
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addYear(),
            'vault_object_id' => '01JTESTESOCIALBX0000000000',
            'activated_at' => now(),
        ]);
    }

    /**
     * @return array{FgtsCompetenceStatus, EsocialEventEvidence}
     */
    private function records(
        Tenant $tenant,
        Client $client,
        string $period = '2026-06',
    ): array {
        $status = FgtsCompetenceStatus::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'competence_period_key' => $period,
                'closure_status' => 'CONFIRMED',
                'totalization_status' => 'PRESENT',
                'guide_status' => 'UNSUPPORTED',
                'payment_status' => 'UNSUPPORTED',
                'coverage' => 'PARTIAL',
                'situation' => 'ATTENTION',
                'last_synced_at' => now(),
                'limitations' => ['Cobertura parcial.'],
                'is_quarantined' => false,
            ]);
        $evidence = EsocialEventEvidence::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'competence_period_key' => $period,
                'event_code' => 'S-1299',
                'content_sha256' => hash('sha256', $tenant->id.':'.$period),
                'byte_size' => 128,
                'source' => 'esocial_bx',
                'observed_at' => now(),
                'is_quarantined' => false,
            ]);

        return [$status, $evidence];
    }

    /**
     * @param  list<string>  $itemFields
     * @return array<string, mixed>
     */
    private function pageStructure(array $itemFields): array
    {
        return [
            'current_page',
            'data' => [
                '*' => $itemFields,
            ],
            'first_page_url',
            'from',
            'last_page',
            'last_page_url',
            'links',
            'next_page_url',
            'path',
            'per_page',
            'prev_page_url',
            'to',
            'total',
        ];
    }
}
