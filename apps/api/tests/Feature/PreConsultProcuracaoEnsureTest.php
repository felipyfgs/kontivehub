<?php

namespace Tests\Feature;

use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\FiscalProfile;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Enums\TaxProxyPowerSource;
use App\Enums\TaxProxyPowerStatus;
use App\Models\Client;
use App\Models\ClientProcuracaoSync;
use App\Models\Establishment;
use App\Models\TaxProxyPower;
use App\Models\Tenant;
use App\Models\TenantSerproAuthorization;
use App\Services\Integra\EnsureClientProcuracaoForConsult;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreConsultProcuracaoEnsureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('fiscal.profile', FiscalProfile::Dev->value);
    }

    public function test_usable_local_power_skips_remote_sync(): void
    {
        [$tenant, $client, $auth] = $this->seedTenantClientAuth();

        TaxProxyPower::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'tenant_serpro_authorization_id' => $auth->id,
            'author_identity' => $auth->author_identity,
            'contributor_cnpj' => '26461528000151',
            'system_code' => 'PGDASD',
            'power_code' => '00146',
            'source' => TaxProxyPowerSource::IntegraProcuracoes,
            'status' => TaxProxyPowerStatus::Active,
            'environment' => SerproEnvironment::Trial->value,
            'provenance' => 'API_VERIFIED',
            'accepted_at' => now(),
            'freshness_checked_at' => now(),
            'verified_at' => now(),
            'valid_to' => now()->addYear(),
        ]);

        $before = TaxProxyPower::query()->where('client_id', $client->id)->count();

        $result = app(EnsureClientProcuracaoForConsult::class)->ensure(
            $tenant,
            $client,
            SerproEnvironment::Trial,
            ['00146'],
        );

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['synced']);
        $this->assertSame($before, TaxProxyPower::query()->where('client_id', $client->id)->count());
    }

    public function test_missing_power_syncs_via_fixture_then_ok(): void
    {
        [$tenant, $client] = $this->seedTenantClientAuth();

        $this->assertSame(0, TaxProxyPower::query()->where('client_id', $client->id)->count());

        $result = app(EnsureClientProcuracaoForConsult::class)->ensure(
            $tenant,
            $client,
            SerproEnvironment::Trial,
            ['00146'],
        );

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertTrue($result['synced']);
        $this->assertTrue(
            TaxProxyPower::query()
                ->where('client_id', $client->id)
                ->where('status', TaxProxyPowerStatus::Active)
                ->where('power_code', '00146')
                ->exists(),
        );
    }

    public function test_certificate_invalidation_marks_sync_unverified_and_ensure_resyncs(): void
    {
        [$tenant, $client, $auth] = $this->seedTenantClientAuth();

        $ensure = app(EnsureClientProcuracaoForConsult::class);
        $first = $ensure->ensure($tenant, $client, SerproEnvironment::Trial, ['00146']);
        $this->assertTrue($first['ok']);

        ClientProcuracaoSync::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'environment' => SerproEnvironment::Trial->value,
            ],
            [
                'status' => ClientProcuracaoSyncStatus::Authorized,
                'last_verified_at' => now(),
                'valid_to' => now()->addYear(),
                'power_codes' => ['00146'],
                'last_check_result' => 'OK',
            ],
        );

        app(TenantSerproAuthorizationService::class)->invalidateDerivedAuthorization(
            $auth,
            $tenant,
            SerproEnvironment::Trial,
            'certificate_removed',
        );

        $auth->refresh();
        $this->assertTrue(
            TaxProxyPower::query()
                ->where('client_id', $client->id)
                ->where('status', TaxProxyPowerStatus::Active)
                ->doesntExist(),
        );

        $sync = ClientProcuracaoSync::query()
            ->where('client_id', $client->id)
            ->first();
        $this->assertSame(ClientProcuracaoSyncStatus::Unverified, $sync?->status);
        $this->assertStringContainsString('INVALIDATED:certificate_removed', (string) $sync?->last_check_result);

        $second = $ensure->ensure($tenant, $client, SerproEnvironment::Trial, ['00146']);
        $this->assertTrue($second['ok'], $second['message'] ?? '');
        $this->assertTrue($second['synced']);
        $this->assertTrue(
            TaxProxyPower::query()
                ->where('client_id', $client->id)
                ->where('status', TaxProxyPowerStatus::Active)
                ->where('power_code', '00146')
                ->exists(),
        );
    }

    /**
     * @return array{0: Tenant, 1: Client, 2: TenantSerproAuthorization}
     */
    private function seedTenantClientAuth(): array
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->bindSystem($tenant);
        $client = Client::factory()->forTenant($tenant)->create([
            'root_cnpj' => '26461528',
        ]);
        Establishment::factory()->forClient($client)->create([
            'tenant_id' => $tenant->id,
            'cnpj' => '26461528000151',
            'is_active' => true,
            'is_headquarters' => true,
        ]);

        $auth = TenantSerproAuthorization::query()->create([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::TokenActive,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '48123272000105',
            'certificate_mode' => AuthorCertificateMode::ManagedCertificate,
            'procurador_token_vault_object_id' => '01JTOKENFIXTURE00000000000',
            'procurador_token_expires_at' => now()->addHours(12),
        ]);

        return [$tenant, $client, $auth];
    }
}
