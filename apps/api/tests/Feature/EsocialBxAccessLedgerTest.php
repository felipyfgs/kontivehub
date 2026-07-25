<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\EsocialBxAccessLedger;
use App\Models\Office;
use App\Models\User;
use App\Support\CurrentOffice;
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
            'office_id',
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

    public function test_global_scope_isolates_offices_and_public_serialization_hides_employer_hash(): void
    {
        $office = Office::factory()->create();
        $otherOffice = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $otherClient = Client::factory()->forOffice($otherOffice)->create();
        $user = User::factory()->forOffice($office)->create();

        $visible = $this->ledger($office, $client, str_repeat('a', 64));
        $this->ledger($otherOffice, $otherClient, str_repeat('b', 64));

        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();
        app(CurrentOffice::class)->resolve($user);

        $this->assertSame([$visible->id], EsocialBxAccessLedger::query()->pluck('id')->all());
        $serialized = $visible->fresh()->toArray();
        $public = $visible->fresh()->toPublicArray();
        $encoded = json_encode([$serialized, $public], JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('employer_hash', $serialized);
        $this->assertArrayNotHasKey('office_id', $public);
        $this->assertStringNotContainsString(str_repeat('a', 64), $encoded);
        $this->assertStringNotContainsString((string) $client->root_cnpj, $encoded);
    }

    private function ledger(Office $office, Client $client, string $employerHash): EsocialBxAccessLedger
    {
        return EsocialBxAccessLedger::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
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
