<?php

namespace App\Services\Fiscal\Mutations;

use App\DTO\Serpro\IntegraRequest;
use App\Enums\MeiProvider;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\FiscalMutationOperation;
use App\Models\OfficeSerproAuthorization;
use App\Services\Integra\ContributorCnpjResolver;
use App\Services\MeiAutomation\MeiProviderPolicy;
use App\Services\Serpro\Catalog\OperationKeyMap;
use App\Services\Serpro\SerproContractService;

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
        $request = $operation->request_sanitized ?? [];
        $payload = [
            'competence' => $operation->competence_period_key,
            'request_keys' => array_keys($businessData),
            'contributor_ref' => $this->maskedReference($contributorCnpj),
            'mutation_operation_id' => (int) $operation->id,
        ];
        if ($this->isMeiDas($operation)) {
            $payload['competencies'] = array_values((array) ($businessData['competencies'] ?? $request['competencies'] ?? []));
            $payload['due_date'] = $businessData['due_date'] ?? $request['due_date'] ?? null;
            $payload['output_format'] = strtoupper((string) ($businessData['output_format'] ?? $request['output_format'] ?? 'PDF'));
        }

        $operationKey = trim((string) $operation->provider_operation_key);
        if ($operationKey === '') {
            $operationKey = OperationKeyMap::require(
                null,
                $operation->solution_code,
                $operation->service_code,
                $operation->operation_code,
            );
        }

        return new IntegraRequest(
            officeId: (int) $operation->office_id,
            clientId: (int) $operation->client_id,
            environment: $environment->value,
            contractorCnpj: $contractorCnpj,
            authorIdentity: $authorIdentity,
            contributorCnpj: $contributorCnpj,
            operationKey: $operationKey,
            businessData: $businessData,
            payload: $payload,
            idempotencyKey: $operation->idempotency_key,
            correlationId: $operation->correlation_id,
            isMutating: true,
            solutionCode: $operation->solution_code,
            serviceCode: $operation->service_code,
            operationCode: $operation->operation_code,
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
        $authorization = OfficeSerproAuthorization::query()
            ->where('office_id', $operation->office_id)
            ->where('environment', $environment->value)
            ->first();
        if ($contract === null || ! $contract->isUsable()) {
            throw new \RuntimeException('Contrato SERPRO indisponível para mutação fiscal.');
        }
        $authorIdentity = trim((string) ($authorization?->author_identity ?? ''));
        if ($authorIdentity === '' || $authorIdentity === '00000000000000') {
            throw new \RuntimeException('Autor do Pedido não configurado para mutação fiscal.');
        }

        return [(string) $contract->contractor_cnpj, $authorIdentity];
    }

    private function portalFirst(FiscalMutationOperation $operation): bool
    {
        if (! $this->isMeiDas($operation)) {
            return false;
        }
        $office = $operation->office()->first();
        if ($office === null) {
            return false;
        }
        $request = $operation->request_sanitized ?? [];
        $operationKey = strtoupper((string) ($request['output_format'] ?? 'PDF')) === 'BARCODE'
            ? 'pgmei.gerardascodbarra'
            : 'pgmei.gerardaspdf';

        return ($this->meiProviders->providers($office, $operationKey)[0] ?? MeiProvider::Serpro)
            !== MeiProvider::Serpro;
    }

    private function isMeiDas(FiscalMutationOperation $operation): bool
    {
        return strtoupper((string) $operation->solution_code) === 'INTEGRA_MEI'
            && strtoupper((string) $operation->service_code) === 'PGMEI'
            && strtoupper((string) $operation->operation_code) === 'GERAR_DAS';
    }

    private function maskedReference(string $cnpj): string
    {
        if (strlen($cnpj) <= 4) {
            return str_repeat('*', strlen($cnpj));
        }

        return substr($cnpj, 0, 2).str_repeat('*', max(0, strlen($cnpj) - 6)).substr($cnpj, -4);
    }
}
