<?php

namespace App\Services\Fiscal\Mutations;

use App\DTO\Serpro\IntegraRequest;
use App\Enums\MeiProvider;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\FiscalMutationOperation;
use App\Models\TenantSerproAuthorization;
use App\Services\Integra\ContributorCnpjResolver;
use App\Services\MeiAutomation\MeiProviderPolicy;
use App\Services\Serpro\SerproContractService;
use RuntimeException;

final class FiscalMutationIntegraRequestFactory
{
    public function __construct(
        private readonly SerproContractService $contracts,
        private readonly ContributorCnpjResolver $contributors,
        private readonly MeiProviderPolicy $meiProviders,
    ) {}

    public function make(FiscalMutationOperation $operation): IntegraRequest
    {
        return $this->build($operation, forceSerpro: false);
    }

    public function makeForSerpro(FiscalMutationOperation $operation): IntegraRequest
    {
        return $this->build($operation, forceSerpro: true);
    }

    private function build(FiscalMutationOperation $operation, bool $forceSerpro): IntegraRequest
    {
        $environment = $operation->environment ?? SerproEnvironment::Trial;
        $client = Client::query()->withoutGlobalScopes()->findOrFail($operation->client_id);
        $contributorCnpj = $this->contributors->resolve($client);
        [$contractorCnpj, $authorIdentity] = $this->identities(
            $operation,
            $environment,
            $contributorCnpj,
            $forceSerpro,
        );

        $businessData = $operation->request_payload_encrypted ?? [];
        $operationKey = trim((string) $operation->operation_key);
        if ($operationKey === '') {
            throw new RuntimeException('operation_key canônica ausente na mutação fiscal.');
        }

        return new IntegraRequest(
            tenantId: (int) $operation->tenant_id,
            clientId: (int) $operation->client_id,
            environment: $environment->value,
            contractorCnpj: $contractorCnpj,
            authorIdentity: $authorIdentity,
            contributorCnpj: $contributorCnpj,
            operationKey: $operationKey,
            businessData: $businessData,
            idempotencyKey: $operation->idempotency_key,
            correlationId: $operation->correlation_id,
            isMutating: true,
            mutationOperationId: (int) $operation->id,
        );
    }

    /** @return array{string, string} */
    private function identities(
        FiscalMutationOperation $operation,
        SerproEnvironment $environment,
        string $contributorCnpj,
        bool $forceSerpro,
    ): array {
        if (! $forceSerpro && $this->portalFirst($operation)) {
            return [$contributorCnpj, $contributorCnpj];
        }

        $contract = $this->contracts->activeFor($environment);
        $authorization = TenantSerproAuthorization::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $operation->tenant_id)
            ->where('environment', $environment->value)
            ->first();
        if ($contract === null || ! $contract->isUsable()) {
            throw new RuntimeException('Contrato SERPRO indisponível para mutação fiscal.');
        }
        $authorIdentity = trim((string) ($authorization?->author_identity ?? ''));
        if ($authorIdentity === '' || $authorIdentity === '00000000000000') {
            throw new RuntimeException('Autor do Pedido não configurado para mutação fiscal.');
        }

        return [(string) $contract->contractor_cnpj, $authorIdentity];
    }

    private function portalFirst(FiscalMutationOperation $operation): bool
    {
        if (! $this->isMeiDas($operation)) {
            return false;
        }
        $tenant = $operation->tenant()->first();
        if ($tenant === null) {
            return false;
        }
        $request = $operation->request_sanitized ?? [];
        $operationKey = strtoupper((string) ($request['output_format'] ?? 'PDF')) === 'BARCODE'
            ? 'pgmei.gerardascodbarra'
            : 'pgmei.gerardaspdf';

        return ($this->meiProviders->providers($tenant, $operationKey)[0] ?? MeiProvider::Serpro)
            !== MeiProvider::Serpro;
    }

    private function isMeiDas(FiscalMutationOperation $operation): bool
    {
        return in_array(strtolower((string) $operation->operation_key), [
            'pgmei.gerardaspdf',
            'pgmei.gerardascodbarra',
        ], true);
    }
}
