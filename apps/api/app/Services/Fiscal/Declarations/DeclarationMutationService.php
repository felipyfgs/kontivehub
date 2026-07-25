<?php

namespace App\Services\Fiscal\Declarations;

use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\FiscalMutationOperation;
use App\Models\Office;
use App\Models\User;
use App\Services\Fiscal\Mutations\FiscalMutationService;
use App\Services\Fiscal\Mutations\MutationPreflightResult;
use App\Services\Integra\ContributorCnpjResolver;
use App\Services\Serpro\Catalog\OfficialServiceCatalogManifest;

/** Fachada das dez mutações produtivas do domínio declarativo. */
final class DeclarationMutationService
{
    public function __construct(
        private readonly DeclarationOperationRegistry $registry,
        private readonly DeclarationMutationPayloadCodec $codec,
        private readonly OfficialServiceCatalogManifest $manifest,
        private readonly ContributorCnpjResolver $contributors,
        private readonly FiscalMutationService $mutations,
    ) {}

    /** @param array<string, mixed> $params */
    public function preflight(
        Office $office,
        Client $client,
        User $user,
        string $actionId,
        array $params,
        string $idempotencyKey,
    ): MutationPreflightResult {
        [$operationKey, $entry, $payload] = $this->definition($client, $actionId, $params);

        return $this->mutations->preflight(
            office: $office,
            client: $client,
            user: $user,
            solutionCode: (string) $entry['id_sistema'],
            serviceCode: (string) $entry['id_sistema'],
            operationCode: (string) $entry['id_servico'],
            competencePeriodKey: $this->competence($params),
            idempotencyKey: $idempotencyKey,
            environment: SerproEnvironment::Production->value,
            requestPayload: $payload,
            module: $this->module((string) $entry['id_sistema']),
            providerOperationKey: $operationKey,
            requireRecentAuth: false,
        );
    }

    /** @param array<string, mixed> $params */
    public function execute(
        Office $office,
        Client $client,
        User $user,
        string $actionId,
        array $params,
        string $idempotencyKey,
        string $preflightToken,
        string $confirmationPhrase,
        bool $confirmed,
    ): FiscalMutationOperation {
        [$operationKey, $entry, $payload] = $this->definition($client, $actionId, $params);

        return $this->mutations->execute(
            office: $office,
            client: $client,
            user: $user,
            solutionCode: (string) $entry['id_sistema'],
            serviceCode: (string) $entry['id_sistema'],
            operationCode: (string) $entry['id_servico'],
            confirmationPhrase: $confirmationPhrase,
            confirmed: $confirmed,
            competencePeriodKey: $this->competence($params),
            idempotencyKey: $idempotencyKey,
            preflightToken: $preflightToken,
            environment: SerproEnvironment::Production->value,
            requestPayload: $payload,
            module: $this->module((string) $entry['id_sistema']),
            providerOperationKey: $operationKey,
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{string, array<string, mixed>, array<string, mixed>}
     */
    private function definition(Client $client, string $actionId, array $params): array
    {
        $operationKey = $this->registry->operationKeyFor($actionId);
        $entry = $this->manifest->findByOperationKey($this->manifest->load(), $operationKey);
        $payload = $this->codec->encode(
            $actionId,
            $params,
            $this->contributors->resolve($client),
        );

        return [$operationKey, $entry, $payload];
    }

    /** @param array<string, mixed> $params */
    private function competence(array $params): ?string
    {
        $value = $params['period_key'] ?? $params['calendar_year'] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    private function module(string $system): string
    {
        return in_array(strtoupper($system), ['DCTFWEB', 'MIT'], true)
            ? 'dctfweb_mit'
            : 'simples_mei';
    }
}
