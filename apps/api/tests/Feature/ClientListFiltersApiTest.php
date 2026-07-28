<?php

namespace Tests\Feature;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\CredentialStatus;
use App\Enums\SyncCursorStatus;
use App\Enums\TaxRegimeCode;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\ClientProcuracaoSync;
use App\Models\Establishment;
use App\Models\SyncCursor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientListFiltersApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_list_supports_created_at_sort_and_returns_growth_stats(): void
    {
        [$user, $tenant] = $this->actor(TenantRole::TenantUser);
        Sanctum::actingAs($user);

        $older = Client::factory()->forTenant($tenant)->create([
            'legal_name' => 'Cliente antigo',
            'created_at' => now()->subDay(),
        ]);
        $newer = Client::factory()->forTenant($tenant)->create([
            'legal_name' => 'Cliente recente',
            'created_at' => now(),
        ]);

        $response = $this->getJson(
            '/api/v1/clients?page=1&per_page=8&sort=created_at&sort_direction=desc&dashboard=true',
        )->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 8)
            ->assertJsonPath('meta.stats.total', 2);

        $response->assertJsonCount(12, 'meta.stats.client_growth_12m');
    }

    public function test_operational_filter_credential_expired_and_capture_problem(): void
    {
        [$user, $tenant] = $this->actor(TenantRole::TenantUser);
        Sanctum::actingAs($user);

        $expired = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Cliente certificado Vencido']);
        $this->credential($expired, CredentialStatus::Expired, now()->subDay());

        $activePastValidTo = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Cliente certificado Ativo Vencido']);
        $this->credential($activePastValidTo, CredentialStatus::Active, now()->subHour());

        $ok = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Cliente certificado Ok']);
        $this->credential($ok, CredentialStatus::Active, now()->addYear());

        $captureProblem = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Cliente Captura Ruim']);
        $est = Establishment::factory()->forClient($captureProblem)->create();
        SyncCursor::query()->create([
            'tenant_id' => $tenant->id,
            'establishment_id' => $est->id,
            'environment' => 'PROD',
            'last_nsu' => 0,
            'status' => SyncCursorStatus::Error,
            'consecutive_decode_failures' => 0,
            'attempts' => 1,
        ]);

        $expiredIds = $this->getJson('/api/v1/clients?operational_filter=credential_expired&per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertEqualsCanonicalizing([$expired->id, $activePastValidTo->id], $expiredIds);
        $this->assertSame(2, (int) $this->getJson('/api/v1/clients')->json('meta.stats.credential_expired'));

        $captureIds = $this->getJson('/api/v1/clients?operational_filter=capture_problem&per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$captureProblem->id], $captureIds);
        $this->assertSame(1, (int) $this->getJson('/api/v1/clients')->json('meta.stats.capture_problem'));
    }

    public function test_credential_presence_stats_and_filters_partition_active_pending_and_absent(): void
    {
        [$user, $tenant] = $this->actor(TenantRole::TenantUser);
        Sanctum::actingAs($user);

        $active = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Credencial ativa']);
        $this->credential($active, CredentialStatus::Active, now()->addYear());
        $pending = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Credencial pendente']);
        $this->credential($pending, CredentialStatus::Pending, now()->addYear());
        $historical = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Só histórico']);
        $this->credential($historical, CredentialStatus::Expired, now()->subDay());
        $absent = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Sem credencial']);

        $stats = $this->getJson('/api/v1/clients?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.stats.with_credential', 2)
            ->assertJsonPath('meta.stats.without_credential', 2);

        $withCredential = $this->getJson(
            '/api/v1/clients?operational_filter=with_credential&per_page=50',
        )->assertOk()->json('data.*.id');
        $withoutCredential = $this->getJson(
            '/api/v1/clients?operational_filter=without_credential&per_page=50',
        )->assertOk()->json('data.*.id');

        $this->assertEqualsCanonicalizing([$active->id, $pending->id], $withCredential);
        $this->assertEqualsCanonicalizing([$historical->id, $absent->id], $withoutCredential);
        $this->assertSame(4, (int) $stats->json('meta.stats.total'));
    }

    public function test_procuracao_statuses_filter_uses_projected_rules(): void
    {
        [$user, $tenant] = $this->actor(TenantRole::TenantUser);
        Sanctum::actingAs($user);

        $authorized = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Proc Autorizada']);
        ClientProcuracaoSync::factory()->forClient($authorized)->authorized()->create();

        $expiring = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Proc A Vencer']);
        ClientProcuracaoSync::factory()->forClient($expiring)->authorized()->create([
            'valid_to' => now()->addDays(10),
        ]);

        $expired = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Proc Vencida']);
        ClientProcuracaoSync::factory()->forClient($expired)->expired()->create();

        $missing = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Proc Ausente']);
        ClientProcuracaoSync::factory()->forClient($missing)->missing()->create();

        $unverifiedExplicit = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Proc Nao Verificada']);
        ClientProcuracaoSync::factory()->forClient($unverifiedExplicit)->create([
            'status' => ClientProcuracaoSyncStatus::Unverified,
        ]);

        $unverifiedAbsent = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Proc Sem Sync']);

        $verifying = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Proc Verificando']);
        ClientProcuracaoSync::factory()->forClient($verifying)->create([
            'status' => ClientProcuracaoSyncStatus::Verifying,
        ]);

        $failed = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Proc Falha']);
        ClientProcuracaoSync::factory()->forClient($failed)->create([
            'status' => ClientProcuracaoSyncStatus::Failed,
        ]);

        $authorizedIds = $this->getJson('/api/v1/clients?procuracao_statuses=authorized&per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$authorized->id], $authorizedIds);

        $expiringIds = $this->getJson('/api/v1/clients?procuracao_statuses=expiring&per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$expiring->id], $expiringIds);

        $expiredIds = $this->getJson('/api/v1/clients?procuracao_statuses=expired&per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$expired->id], $expiredIds);

        $missingIds = $this->getJson('/api/v1/clients?procuracao_statuses=missing&per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$missing->id], $missingIds);

        $unverifiedIds = $this->getJson('/api/v1/clients?procuracao_statuses=unverified&per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertEqualsCanonicalizing(
            [$unverifiedExplicit->id, $unverifiedAbsent->id],
            $unverifiedIds,
        );

        $verifyingIds = $this->getJson('/api/v1/clients?procuracao_statuses=verifying&per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$verifying->id], $verifyingIds);

        $failedIds = $this->getJson('/api/v1/clients?procuracao_statuses=failed&per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$failed->id], $failedIds);

        $multi = $this->getJson('/api/v1/clients?procuracao_statuses=missing,expiring&per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertEqualsCanonicalizing([$missing->id, $expiring->id], $multi);
    }

    public function test_tax_regimes_filter_accepts_only_canonical_values(): void
    {
        [$user, $tenant] = $this->actor(TenantRole::TenantUser);
        Sanctum::actingAs($user);

        $canonical = Client::factory()->forTenant($tenant)->create([
            'legal_name' => 'Regime Canonico',
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);
        $unknown = Client::factory()->forTenant($tenant)->create([
            'legal_name' => 'Regime não informado',
            'tax_regime' => TaxRegimeCode::Unknown->value,
        ]);
        $other = Client::factory()->forTenant($tenant)->create([
            'legal_name' => 'Regime MEI',
            'tax_regime' => TaxRegimeCode::Mei->value,
        ]);

        $ids = $this->getJson('/api/v1/clients?tax_regimes=SIMPLES_NACIONAL&per_page=50')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$canonical->id], $ids);
        $this->assertNotContains($other->id, $ids);

        $unknownIds = $this->getJson('/api/v1/clients?tax_regimes=UNKNOWN&per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$unknown->id], $unknownIds);
    }

    /** @return array{User, Tenant} */
    private function actor(TenantRole $role): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, $role)->create();

        return [$user, $tenant];
    }

    private function credential(Client $client, CredentialStatus $status, mixed $validTo): ClientCredential
    {
        return ClientCredential::query()->create([
            'tenant_id' => $client->tenant_id,
            'client_id' => $client->id,
            'status' => $status,
            'subject_name' => $client->legal_name,
            'holder_cnpj' => $client->root_cnpj.'000180',
            'fingerprint_sha256' => hash('sha256', 'test-'.$client->id.'-'.$status->value),
            'valid_from' => now()->subYear(),
            'valid_to' => $validTo,
            'vault_object_id' => (string) Str::ulid(),
            'activated_at' => now()->subMonths(3),
            'expires_alert_30' => false,
            'expires_alert_7' => false,
            'expires_alert_1' => false,
        ]);
    }
}
