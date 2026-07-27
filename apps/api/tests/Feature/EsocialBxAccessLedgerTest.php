<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\EsocialBxAccessLedger;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EsocialBxAccessLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_is_tenant_scoped_and_has_no_sensitive_payload_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('esocial_bx_access_ledgers', [
            'tenant_id',
            'client_id',
            'employer_hash',
            'environment',
            'operation',
            'access_date',
            'status',
            'http_status',
            'official_code',
            'retryable',
            'correlation_id',
            'finished_at',
        ]));

        $columns = Schema::getColumnListing('esocial_bx_access_ledgers');
        foreach (['payload', 'xml', 'pfx', 'password', 'cnpj', 'certificate', 'private_key'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    public function test_global_scope_isolates_tenants_and_public_serialization_hides_employer_hash(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $user = User::factory()->forTenant($tenant)->create();

        $visible = $this->ledger($tenant, $client, str_repeat('a', 64));
        $this->ledger($otherTenant, $otherClient, str_repeat('b', 64));

        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
        app(CurrentTenant::class)->resolve($user);

        $this->assertSame([$visible->id], EsocialBxAccessLedger::query()->pluck('id')->all());
        $serialized = $visible->fresh()->toArray();
        $public = $visible->fresh()->toPublicArray();
        $encoded = json_encode([$serialized, $public], JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('employer_hash', $serialized);
        $this->assertArrayNotHasKey('tenant_id', $public);
        $this->assertStringNotContainsString(str_repeat('a', 64), $encoded);
        $this->assertStringNotContainsString((string) $client->root_cnpj, $encoded);
    }

    private function ledger(Tenant $tenant, Client $client, string $employerHash): EsocialBxAccessLedger
    {
        return EsocialBxAccessLedger::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'employer_hash' => $employerHash,
            'environment' => 'restricted',
            'operation' => 'IDENTIFIERS_S-1299',
            'access_date' => '2026-07-22',
            'status' => 'RESERVED',
            'retryable' => false,
        ]);
    }
}
