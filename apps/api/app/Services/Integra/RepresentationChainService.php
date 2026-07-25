<?php

namespace App\Services\Integra;

use App\Domain\BrazilianTaxId;
use App\DTO\Serpro\RepresentationChain;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Services\Serpro\SerproContractService;
use RuntimeException;
use Throwable;

/**
 * Resolve e valida a cadeia contratante → autor → contribuinte antes de ops reais.
 */
final class RepresentationChainService
{
    public function __construct(
        private readonly SerproContractService $contracts,
        private readonly OfficeSerproAuthorizationService $authorizations,
        private readonly ContributorCnpjResolver $contributors,
    ) {}

    public function resolve(
        Office $office,
        Client $client,
        SerproEnvironment $environment,
        ?OfficeSerproAuthorization $auth = null,
    ): RepresentationChain {
        $missing = [];
        $contractor = '';
        $author = '';
        $authorType = '';
        $contributor = '';

        if ($client->office_id !== $office->id) {
            return new RepresentationChain(
                contractorCnpj: '',
                authorIdentity: '',
                authorIdentityType: '',
                contributorCnpj: '',
                officeId: (int) $office->id,
                clientId: (int) $client->id,
                environment: $environment->value,
                complete: false,
                missingLinks: ['CONTRIBUTOR_CROSS_TENANT'],
                blockReason: 'Contribuinte não pertence ao escritório corrente.',
            );
        }

        $contract = $this->contracts->activeFor($environment);
        if ($contract === null || ! $contract->isUsable()) {
            $missing[] = 'CONTRACTOR';
        } else {
            try {
                $contractor = BrazilianTaxId::parseCnpj((string) $contract->contractor_cnpj)->value();
            } catch (Throwable) {
                $missing[] = 'CONTRACTOR';
            }
        }

        $auth ??= $this->authorizations->getOrCreate($office, $environment);
        $author = strtoupper(trim((string) $auth->author_identity));
        $authorType = $auth->author_identity_type?->value
            ?? (string) $auth->author_identity_type;

        if (
            $author === ''
            || $author === '00000000000000'
            || $author === '00000000000'
            || in_array($auth->status, [
                SerproAuthorizationStatus::Draft,
                SerproAuthorizationStatus::PendingTerm,
                SerproAuthorizationStatus::Revoked,
            ], true)
        ) {
            $missing[] = 'AUTHOR';
        } else {
            // Formato canônico: CPF 11 dígitos ou CNPJ 14 alfanumérico (DV estrito no onboarding).
            $authorNorm = BrazilianTaxId::normalize($author);
            $authorOk = (strlen($authorNorm) === 11 && ctype_digit($authorNorm))
                || (strlen($authorNorm) === 14 && (bool) preg_match('/^[0-9A-Z]{14}$/', $authorNorm));
            if (! $authorOk) {
                $missing[] = 'AUTHOR';
            } else {
                $author = $authorNorm;
            }
        }

        try {
            $contributor = $this->contributors->resolve($client);
            BrazilianTaxId::parseCnpj($contributor);
        } catch (Throwable) {
            $missing[] = 'CONTRIBUTOR';
            $contributor = '';
        }

        $complete = $missing === [];
        $block = null;
        if (! $complete) {
            $block = 'Cadeia de representação incompleta: '.implode(', ', $missing);
        }

        return new RepresentationChain(
            contractorCnpj: $contractor,
            authorIdentity: $author,
            authorIdentityType: $authorType,
            contributorCnpj: $contributor,
            officeId: (int) $office->id,
            clientId: (int) $client->id,
            environment: $environment->value,
            complete: $complete,
            missingLinks: $missing,
            blockReason: $block,
        );
    }

    /**
     * Fail-closed para operações reais: lança se qualquer elo faltar.
     */
    public function assertComplete(
        Office $office,
        Client $client,
        SerproEnvironment $environment,
        ?OfficeSerproAuthorization $auth = null,
    ): RepresentationChain {
        $chain = $this->resolve($office, $client, $environment, $auth);
        if (! $chain->isComplete()) {
            throw new RuntimeException($chain->blockReason ?? 'Cadeia de representação incompleta.');
        }

        return $chain;
    }
}
