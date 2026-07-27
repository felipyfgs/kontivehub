<?php

namespace App\DTO\Serpro;

use App\Models\Client;
use App\Models\Tenant;

/**
 * Comando de domínio para o executor central Integra Contador.
 *
 * Callers informam operation_key + dados de negócio; coordenadas, auth e
 * ledger são resolvidos pelo executor/catálogo.
 */
final class SerproOperationCommand
{
    /**
     * @param  array<string, mixed>  $businessData
     * @param  array<string, string>  $headers  Headers extras não secretos
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly ?Client $client,
        public readonly string $operationKey,
        public readonly array $businessData = [],
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $entityKey = null,
        public readonly ?MutationAuthorization $mutationAuth = null,
        public readonly ?string $environment = null,
        public readonly array $headers = [],
        public readonly ?string $module = null,
        public readonly ?string $contributorIdentityOverride = null,
        public readonly ?string $authorIdentityOverride = null,
        public readonly ?EventosBatchContributor $eventosBatchContributor = null,
        public readonly ?int $mutationOperationId = null,
    ) {}

    public function mutationAuthOrNone(): MutationAuthorization
    {
        return $this->mutationAuth ?? MutationAuthorization::none();
    }

    /**
     * Chave de entidade namespaced (cliente ou override).
     */
    public function resolvedEntityKey(): string
    {
        if ($this->entityKey !== null && $this->entityKey !== '') {
            return $this->entityKey;
        }

        if ($this->client !== null) {
            return 'client:'.(string) $this->client->id;
        }

        return 'tenant:'.(string) $this->tenant->id;
    }
}
