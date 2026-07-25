<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Contracts\SecureObjectStore;
use App\Enums\SecureObjectPurpose;
use App\Models\Client;
use App\Models\DefisDeclarationReference;
use App\Models\Office;
use Carbon\CarbonImmutable;
use RuntimeException;

/** Cofre de referências DEFIS; nunca retorna idDefis a chamadas públicas. */
final class DefisDeclarationReferenceStore
{
    public function __construct(private readonly SecureObjectStore $vault) {}

    public function store(Office $office, Client $client, string $idDefis, ?int $runId, string $provenance): DefisDeclarationReference
    {
        $this->assertId($idDefis);
        foreach (DefisDeclarationReference::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)->where('client_id', $client->id)->get() as $existing) {
            if (hash_equals($idDefis, $this->read($existing, $office))) {
                return $existing;
            }
        }

        $objectId = $this->vault->put($idDefis, $this->aad((int) $office->id, (int) $client->id));

        return DefisDeclarationReference::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'vault_object_id' => $objectId,
            'observed_at' => CarbonImmutable::now(),
            'source_run_id' => $runId,
            'source_provenance' => $provenance,
        ]);
    }

    public function read(DefisDeclarationReference $reference, Office $office): string
    {
        if ((int) $reference->office_id !== (int) $office->id) {
            throw new RuntimeException('Referência DEFIS não pertence ao escritório ativo.');
        }
        $id = $this->vault->get($reference->vault_object_id, $this->aad((int) $office->id, (int) $reference->client_id));
        $this->assertId($id);

        return $id;
    }

    /** @return array<string, scalar|null> */
    private function aad(int $officeId, int $clientId): array
    {
        return SecureObjectPurpose::FiscalDefisReference->aadBase(['office_id' => $officeId, 'client_id' => $clientId]);
    }

    private function assertId(string $id): void
    {
        if (preg_match('/^\d{1,32}$/', $id) !== 1) {
            throw new RuntimeException('Referência DEFIS inválida.');
        }
    }
}
