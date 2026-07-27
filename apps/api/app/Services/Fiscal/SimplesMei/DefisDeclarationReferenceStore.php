<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Contracts\SecureObjectStore;
use App\Enums\SecureObjectPurpose;
use App\Models\Client;
use App\Models\DefisDeclarationReference;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use RuntimeException;

/** Cofre de referências DEFIS; nunca retorna idDefis a chamadas públicas. */
final class DefisDeclarationReferenceStore
{
    public function __construct(private readonly SecureObjectStore $vault) {}

    public function store(Tenant $tenant, Client $client, string $idDefis, ?int $runId, string $provenance): DefisDeclarationReference
    {
        $this->assertId($idDefis);
        foreach (DefisDeclarationReference::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('client_id', $client->id)->get() as $existing) {
            if (hash_equals($idDefis, $this->read($existing, $tenant))) {
                return $existing;
            }
        }

        $objectId = $this->vault->put($idDefis, $this->aad((int) $tenant->id, (int) $client->id));

        return DefisDeclarationReference::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'vault_object_id' => $objectId,
            'observed_at' => CarbonImmutable::now(),
            'source_run_id' => $runId,
            'source_provenance' => $provenance,
        ]);
    }

    public function read(DefisDeclarationReference $reference, Tenant $tenant): string
    {
        if ((int) $reference->tenant_id !== (int) $tenant->id) {
            throw new RuntimeException('Referência DEFIS não pertence ao escritório ativo.');
        }
        $id = $this->vault->get($reference->vault_object_id, $this->aad((int) $tenant->id, (int) $reference->client_id));
        $this->assertId($id);

        return $id;
    }

    /** @return array<string, scalar|null> */
    private function aad(int $tenantId, int $clientId): array
    {
        return SecureObjectPurpose::FiscalDefisReference->aadBase(['tenant_id' => $tenantId, 'client_id' => $clientId]);
    }

    private function assertId(string $id): void
    {
        if (preg_match('/^\d{1,32}$/', $id) !== 1) {
            throw new RuntimeException('Referência DEFIS inválida.');
        }
    }
}
