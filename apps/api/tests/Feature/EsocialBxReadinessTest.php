<?php

namespace Tests\Feature;

use App\DTO\Esocial\EsocialBxReadiness;
use App\Enums\CredentialStatus;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use App\Enums\OfficeRole;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\EsocialBxAccessLedger;
use App\Models\FiscalModuleControl;
use App\Models\Office;
use App\Models\User;
use App\Services\Esocial\EsocialBxAccessGuard;
use App\Services\Esocial\EsocialBxReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EsocialBxReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-15 12:00:00-03:00');
        config()->set('fgts_esocial.driver', 'official_bx');
        config()->set('fgts_esocial.environment', 'restricted');
        config()->set('fgts_esocial.kill_switch', false);
        config()->set('fgts_esocial.official_bx.daily_access_limit', 10);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_readiness_is_tenant_scoped_and_ready_with_active_a1(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $credential = $this->credential($client);

        $readiness = app(EsocialBxReadinessService::class)->check($office, $client);

        $this->assertTrue($readiness->ready);
        $this->assertSame(10, $readiness->locallyRemaining);
        $public = $readiness->toArray();
        $this->assertSame(substr($credential->fingerprint_sha256, -12), $public['credential']['fingerprint_suffix']);
        $this->assertArrayNotHasKey('vault_object_id', $public['credential']);

        $other = Office::factory()->create();
        $foreign = app(EsocialBxReadinessService::class)->check($other, $client);
        $this->assertFalse($foreign->ready);
        $this->assertContains('ESOCIAL_BX_CLIENT_NOT_FOUND', array_column($foreign->blockers, 'code'));
    }

    public function test_window_and_conservative_quota_block_before_egress(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $this->credential($client);
        $guard = app(EsocialBxAccessGuard::class);

        for ($index = 0; $index < 10; $index++) {
            EsocialBxAccessLedger::query()->withoutGlobalScopes()->create([
                'office_id' => $office->id,
                'client_id' => $client->id,
                'employer_hash' => $guard->employerHash($client),
                'environment' => 'restricted',
                'operation' => 'IDENTIFIERS_S-1299',
                'access_date' => '2026-07-15',
                'status' => 'FAILED',
            ]);
        }
        $quota = app(EsocialBxReadinessService::class)->check($office, $client);
        $this->assertFalse($quota->ready);
        $this->assertContains('ESOCIAL_BX_QUOTA_EXHAUSTED', array_column($quota->blockers, 'code'));

        CarbonImmutable::setTestNow('2026-08-05 12:00:00-03:00');
        $window = app(EsocialBxReadinessService::class)->check($office, $client);
        $this->assertContains('ESOCIAL_BX_BLOCKED_WINDOW', array_column($window->blockers, 'code'));
    }

    public function test_readiness_endpoint_is_sanitized_and_cannot_cross_office(): void
    {
        $office = Office::factory()->create();
        $other = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $foreign = Client::factory()->forOffice($other)->create();
        $this->credential($client);
        Sanctum::actingAs(User::factory()->forOffice($office, OfficeRole::Viewer)->create());

        $response = $this->getJson('/api/v1/fiscal/fgts/readiness?client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonMissingPath('data.credential.vault_object_id')
            ->assertJsonMissingPath('data.credential.password');
        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) $client->root_cnpj, $encoded);

        $this->getJson('/api/v1/fiscal/fgts/readiness?client_id='.$foreign->id)->assertNotFound();
    }

    public function test_missing_expired_future_and_mismatched_credentials_are_reported_without_materialization(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $service = app(EsocialBxReadinessService::class);

        $missing = $service->check($office, $client);
        $this->assertContains('ESOCIAL_BX_CREDENTIAL_MISSING', array_column($missing->blockers, 'code'));

        $expired = $this->credential($client, [
            'valid_from' => now()->subYear(),
            'valid_to' => now()->subMinute(),
        ]);
        $expiredResult = $service->check($office, $client);
        $this->assertContains('ESOCIAL_BX_CREDENTIAL_EXPIRED', array_column($expiredResult->blockers, 'code'));

        $expired->forceFill([
            'valid_from' => now()->addDay(),
            'valid_to' => now()->addYear(),
        ])->save();
        $future = $service->check($office, $client);
        $this->assertContains('ESOCIAL_BX_CREDENTIAL_NOT_YET_VALID', array_column($future->blockers, 'code'));

        $expired->forceFill([
            'holder_cnpj' => '99999999000100',
            'valid_from' => now()->subDay(),
        ])->save();
        $mismatch = $service->check($office, $client);
        $this->assertContains('ESOCIAL_BX_CREDENTIAL_IDENTITY_MISMATCH', array_column($mismatch->blockers, 'code'));
    }

    public function test_config_feature_and_kill_switch_block_before_credential_query(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $this->credential($client);
        $service = app(EsocialBxReadinessService::class);

        config()->set('fgts_esocial.environment', 'production');
        config()->set('fgts_esocial.production_egress_enabled', false);
        $this->assertNoCredentialQuery(
            fn () => $service->check($office, $client),
            'ESOCIAL_BX_PRODUCTION_EGRESS_DISABLED',
        );

        config()->set('fgts_esocial.environment', 'restricted');
        FiscalModuleControl::query()->create([
            'module_key' => FiscalControlModule::Fgts,
            'scope' => FiscalModuleControlScope::Office,
            'office_id' => $office->id,
            'restricted' => true,
            'reason' => 'Coorte de teste bloqueada.',
            'updated_by_user_id' => User::factory()->forOffice($office)->create()->id,
        ]);
        $this->assertNoCredentialQuery(
            fn () => $service->check($office, $client),
            'ESOCIAL_BX_FEATURE_DISABLED',
        );

        FiscalModuleControl::query()->delete();
        config()->set('fgts_esocial.kill_switch', true);
        $this->assertNoCredentialQuery(
            fn () => $service->check($office, $client),
            'ESOCIAL_BX_KILL_SWITCH',
        );
    }

    /** @param array<string, mixed> $overrides */
    private function credential(Client $client, array $overrides = []): ClientCredential
    {
        return ClientCredential::query()->withoutGlobalScopes()->create(array_merge([
            'office_id' => $client->office_id,
            'client_id' => $client->id,
            'status' => CredentialStatus::Active,
            'subject_name' => 'Certificado de teste',
            'holder_cnpj' => $client->root_cnpj.'000100',
            'fingerprint_sha256' => str_repeat('a', 64),
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addYear(),
            'vault_object_id' => '01JTESTESOCIALBX0000000000',
            'activated_at' => now(),
        ], $overrides));
    }

    /** @param callable():EsocialBxReadiness $callback */
    private function assertNoCredentialQuery(callable $callback, string $expectedCode): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $result = $callback();

        $this->assertContains($expectedCode, array_column($result->blockers, 'code'));
        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'client_credentials'),
        ));
    }
}
