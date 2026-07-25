<?php

namespace App\Services\Integra\Sitfis;

use App\Contracts\SitfisIdentityResolving;
use App\Domain\Cnpj;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Office;
use App\Models\SerproContract;
use App\Services\Integra\OfficeSerproAuthorizationService;
use App\Services\Serpro\SerproContractService;
use RuntimeException;

/**
 * Resolve identidades da cadeia Integra (contratante → autor → contribuinte)
 * a partir de registros persistidos — nunca do frontend.
 */
final class SitfisIdentityResolver implements SitfisIdentityResolving
{
    public function __construct(
        private readonly SerproContractService $contracts,
        private readonly OfficeSerproAuthorizationService $authorizations,
    ) {}

    /**
     * @return array{
     *     environment: SerproEnvironment,
     *     contract: SerproContract,
     *     contractor_cnpj: string,
     *     author_identity: string,
     *     contributor_cnpj: string
     * }
     */
    public function resolve(Office $office, Client $client): array
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }

        $envValue = (string) config('serpro.default_environment', 'TRIAL');
        $environment = SerproEnvironment::tryFrom($envValue) ?? SerproEnvironment::Trial;

        $contract = $this->contracts->activeFor($environment);
        if ($contract === null || ! $contract->isUsable()) {
            throw new RuntimeException('Contrato SERPRO indisponível para SITFIS.');
        }

        $auth = $this->authorizations->getOrCreate($office, $environment);
        $author = (string) ($auth->author_identity ?? '');
        if ($author === '' || $author === '00000000000000') {
            throw new RuntimeException('Autor do Pedido não configurado para o escritório.');
        }

        $contributor = $this->resolveContributorCnpj($client);
        if ($contributor === null) {
            throw new RuntimeException('CNPJ do contribuinte (estabelecimento) não encontrado.');
        }

        return [
            'environment' => $environment,
            'contract' => $contract,
            'contractor_cnpj' => strtoupper((string) $contract->contractor_cnpj),
            'author_identity' => strtoupper($author),
            'contributor_cnpj' => $contributor,
        ];
    }

    public function resolveContributorCnpj(Client $client): ?string
    {
        $est = Establishment::query()
            ->withoutGlobalScopes()
            ->where('office_id', $client->office_id)
            ->where('client_id', $client->id)
            ->where('is_active', true)
            ->orderByDesc('is_matrix')
            ->orderBy('id')
            ->first();

        if ($est !== null && is_string($est->cnpj) && $est->cnpj !== '') {
            $parsed = Cnpj::tryParse($est->cnpj);

            return $parsed?->value() ?? strtoupper(Cnpj::normalize($est->cnpj));
        }

        return null;
    }
}
