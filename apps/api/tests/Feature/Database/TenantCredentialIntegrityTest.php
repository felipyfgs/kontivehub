<?php

namespace Tests\Feature\Database;

use App\Enums\CredentialStatus;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\TenantCredential;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class TenantCredentialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_historical_fingerprint_is_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $fingerprint = hash('sha256', 'repeated-certificate');

        TenantCredential::factory()->count(3)->forTenant($tenant)->create([
            'status' => CredentialStatus::Superseded,
            'fingerprint_sha256' => $fingerprint,
        ]);

        self::assertSame(
            3,
            TenantCredential::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('fingerprint_sha256', $fingerprint)
                ->count(),
        );
    }

    public function test_purpose_link_cannot_reference_credential_from_another_tenant(): void
    {
        $credentialTenant = Tenant::factory()->create();
        $linkTenant = Tenant::factory()->create();
        $credential = TenantCredential::factory()->forTenant($credentialTenant)->create();

        $this->expectException(QueryException::class);

        DB::table('tenant_credential_purpose_links')->insert([
            'tenant_id' => $linkTenant->id,
            'tenant_credential_id' => $credential->id,
            'purpose' => 'SERPRO_TERM_SIGNING',
            'status' => CredentialStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_fgts_representation_cannot_reference_credential_from_another_tenant(): void
    {
        $credentialTenant = Tenant::factory()->create();
        $representationTenant = Tenant::factory()->create();
        $credential = TenantCredential::factory()->forTenant($credentialTenant)->create();
        $client = Client::factory()->forTenant($representationTenant)->create();

        $this->expectException(QueryException::class);

        DB::table('fgts_digital_representations')->insert([
            'tenant_id' => $representationTenant->id,
            'client_id' => $client->id,
            'tenant_credential_id' => $credential->id,
            'credential_source' => 'TENANT',
            'profile_type' => 'PROCURADOR_PJ',
            'target_identifier_hash' => hash('sha256', 'target'),
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_deleting_credential_cascades_purpose_link_and_nulls_only_fgts_reference(): void
    {
        $tenant = Tenant::factory()->create();
        $credential = TenantCredential::factory()->forTenant($tenant)->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $purposeLinkId = DB::table('tenant_credential_purpose_links')->insertGetId([
            'tenant_id' => $tenant->id,
            'tenant_credential_id' => $credential->id,
            'purpose' => 'SERPRO_TERM_SIGNING',
            'status' => CredentialStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $representationId = DB::table('fgts_digital_representations')->insertGetId([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'tenant_credential_id' => $credential->id,
            'credential_source' => 'TENANT',
            'profile_type' => 'PROCURADOR_PJ',
            'target_identifier_hash' => hash('sha256', 'target'),
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $credential->delete();

        $this->assertDatabaseMissing('tenant_credential_purpose_links', [
            'id' => $purposeLinkId,
        ]);
        $this->assertDatabaseHas('fgts_digital_representations', [
            'id' => $representationId,
            'tenant_id' => $tenant->id,
            'tenant_credential_id' => null,
        ]);
    }

    public function test_rollback_rejects_historical_duplicates_before_changing_constraints(): void
    {
        $tenant = Tenant::factory()->create();
        TenantCredential::factory()->count(2)->forTenant($tenant)->create([
            'status' => CredentialStatus::Superseded,
            'fingerprint_sha256' => hash('sha256', 'rollback-duplicate'),
        ]);
        $migration = require database_path(
            'migrations/2026_07_28_030000_enforce_tenant_credential_tenancy.php',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rollback bloqueado');

        $migration->down();
    }
}
