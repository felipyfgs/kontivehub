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
use App\Models\ClientProcuracaoSnapshot;
use App\Models\Establishment;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\TaxProxyPower;
use App\Services\Integra\EnsureClientProcuracaoForConsult;
use App\Services\Integra\OfficeSerproAuthorizationService;
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
        [$office, $client, $auth] = $this->seedOfficeClientAuth();

        TaxProxyPower::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'office_serpro_authorization_id' => $auth->id,
            'author_identity' => $auth->author_identity,
            'contributor_cnpj' => '26461528000151',
            'system_code' => 'PGDASD',
            'power_code' => 'PGDASD',
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
            $office,
            $client,
            SerproEnvironment::Trial,
            ['PGDASD'],
        );

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['synced']);
        $this->assertSame($before, TaxProxyPower::query()->where('client_id', $client->id)->count());
    }

    public function test_missing_power_syncs_via_fixture_then_ok(): void
    {
        [$office, $client] = $this->seedOfficeClientAuth();

        $this->assertSame(0, TaxProxyPower::query()->where('client_id', $client->id)->count());

        $result = app(EnsureClientProcuracaoForConsult::class)->ensure(
            $office,
            $client,
            SerproEnvironment::Trial,
            ['PGDASD'],
        );

        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertTrue($result['synced']);
        $this->assertTrue(
            TaxProxyPower::query()
                ->where('client_id', $client->id)
                ->where('status', TaxProxyPowerStatus::Active)
                ->whereIn('power_code', ['PGDASD', '00146'])
                ->exists(),
        );
    }

    public function test_a1_invalidation_marks_snapshot_unverified_and_ensure_resyncs(): void
    {
        [$office, $client, $auth] = $this->seedOfficeClientAuth();

        $ensure = app(EnsureClientProcuracaoForConsult::class);
        $first = $ensure->ensure($office, $client, SerproEnvironment::Trial, ['PGDASD']);
        $this->assertTrue($first['ok']);

        ClientProcuracaoSnapshot::query()->updateOrCreate(
            [
                'office_id' => $office->id,
                'client_id' => $client->id,
                'environment' => SerproEnvironment::Trial->value,
            ],
            [
                'status' => ClientProcuracaoSyncStatus::Authorized,
                'last_verified_at' => now(),
                'valid_to' => now()->addYear(),
                'power_codes' => ['PGDASD', '00146'],
                'last_check_result' => 'OK',
            ],
        );

        app(OfficeSerproAuthorizationService::class)->invalidateDerivedAuthorization(
            $auth,
            $office,
            SerproEnvironment::Trial,
            'a1_removed',
        );

        $auth->refresh();
        $this->assertTrue(
            TaxProxyPower::query()
                ->where('client_id', $client->id)
                ->where('status', TaxProxyPowerStatus::Active)
                ->doesntExist(),
        );

        $snap = ClientProcuracaoSnapshot::query()
            ->where('client_id', $client->id)
            ->first();
        $this->assertSame(ClientProcuracaoSyncStatus::Unverified, $snap?->status);
        $this->assertStringContainsString('INVALIDATED:a1_removed', (string) $snap?->last_check_result);

        $second = $ensure->ensure($office, $client, SerproEnvironment::Trial, ['PGDASD']);
        $this->assertTrue($second['ok'], $second['message'] ?? '');
        $this->assertTrue($second['synced']);
        $this->assertTrue(
            TaxProxyPower::query()
                ->where('client_id', $client->id)
                ->where('status', TaxProxyPowerStatus::Active)
                ->whereIn('power_code', ['PGDASD', '00146'])
                ->exists(),
        );
    }

    /**
     * @return array{0: Office, 1: Client, 2: OfficeSerproAuthorization}
     */
    private function seedOfficeClientAuth(): array
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create([
            'root_cnpj' => '26461528',
        ]);
        Establishment::factory()->forClient($client)->create([
            'office_id' => $office->id,
            'cnpj' => '26461528000151',
            'is_active' => true,
            'is_matrix' => true,
        ]);

        $auth = OfficeSerproAuthorization::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::TokenActive,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '48123272000105',
            'certificate_mode' => AuthorCertificateMode::ManagedA1,
            'managed_a1_consent' => true,
            'procurador_token_vault_object_id' => '01JTOKENFIXTURE00000000000',
            'procurador_token_expires_at' => now()->addHours(12),
        ]);

        return [$office, $client, $auth];
    }
}
