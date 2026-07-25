<?php

namespace Tests\Feature;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\CredentialStatus;
use App\Enums\OfficeRole;
use App\Enums\SyncCursorStatus;
use App\Enums\TaxRegimeCode;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\ClientProcuracaoSync;
use App\Models\Establishment;
use App\Models\Office;
use App\Models\SyncCursor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientListFiltersApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_filter_credential_expired_and_capture_problem(): void
    {
        [$user, $office] = $this->actor(OfficeRole::Viewer);
        Sanctum::actingAs($user);

        $expired = Client::factory()->forOffice($office)->create(['legal_name' => 'Cliente A1 Vencido']);
        $this->credential($expired, CredentialStatus::Expired, now()->subDay());

        $activePastValidTo = Client::factory()->forOffice($office)->create(['legal_name' => 'Cliente A1 Ativo Vencido']);
        $this->credential($activePastValidTo, CredentialStatus::Active, now()->subHour());

        $ok = Client::factory()->forOffice($office)->create(['legal_name' => 'Cliente A1 Ok']);
        $this->credential($ok, CredentialStatus::Active, now()->addYear());

        $captureProblem = Client::factory()->forOffice($office)->create(['legal_name' => 'Cliente Captura Ruim']);
        $est = Establishment::factory()->forClient($captureProblem)->create();
        SyncCursor::query()->create([
            'office_id' => $office->id,
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

    public function test_procuracao_statuses_filter_uses_projected_rules(): void
    {
        [$user, $office] = $this->actor(OfficeRole::Viewer);
        Sanctum::actingAs($user);

        $authorized = Client::factory()->forOffice($office)->create(['legal_name' => 'Proc Autorizada']);
        ClientProcuracaoSync::factory()->forClient($authorized)->authorized()->create();

        $expiring = Client::factory()->forOffice($office)->create(['legal_name' => 'Proc A Vencer']);
        ClientProcuracaoSync::factory()->forClient($expiring)->authorized()->create([
            'valid_to' => now()->addDays(10),
        ]);

        $expired = Client::factory()->forOffice($office)->create(['legal_name' => 'Proc Vencida']);
        ClientProcuracaoSync::factory()->forClient($expired)->expired()->create();

        $missing = Client::factory()->forOffice($office)->create(['legal_name' => 'Proc Ausente']);
        ClientProcuracaoSync::factory()->forClient($missing)->missing()->create();

        $unverifiedExplicit = Client::factory()->forOffice($office)->create(['legal_name' => 'Proc Nao Verificada']);
        ClientProcuracaoSync::factory()->forClient($unverifiedExplicit)->create([
            'status' => ClientProcuracaoSyncStatus::Unverified,
        ]);

        $unverifiedAbsent = Client::factory()->forOffice($office)->create(['legal_name' => 'Proc Sem Sync']);

        $verifying = Client::factory()->forOffice($office)->create(['legal_name' => 'Proc Verificando']);
        ClientProcuracaoSync::factory()->forClient($verifying)->create([
            'status' => ClientProcuracaoSyncStatus::Verifying,
        ]);

        $failed = Client::factory()->forOffice($office)->create(['legal_name' => 'Proc Falha']);
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

    public function test_tax_regimes_filter_matches_legacy_storage_labels(): void
    {
        [$user, $office] = $this->actor(OfficeRole::Viewer);
        Sanctum::actingAs($user);

        $canonical = Client::factory()->forOffice($office)->create([
            'legal_name' => 'Regime Canonico',
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);
        $legacy = Client::factory()->forOffice($office)->create([
            'legal_name' => 'Regime Legado',
            'tax_regime' => 'SIMPLES',
        ]);
        $other = Client::factory()->forOffice($office)->create([
            'legal_name' => 'Regime MEI',
            'tax_regime' => TaxRegimeCode::Mei->value,
        ]);

        $ids = $this->getJson('/api/v1/clients?tax_regimes=SIMPLES_NACIONAL&per_page=50')
            ->assertOk()
            ->json('data.*.id');

        $this->assertEqualsCanonicalizing([$canonical->id, $legacy->id], $ids);
        $this->assertNotContains($other->id, $ids);
    }

    /** @return array{User, Office} */
    private function actor(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, $role)->create();

        return [$user, $office];
    }

    private function credential(Client $client, CredentialStatus $status, mixed $validTo): ClientCredential
    {
        return ClientCredential::query()->create([
            'office_id' => $client->office_id,
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
