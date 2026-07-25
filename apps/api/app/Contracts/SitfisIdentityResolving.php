<?php

namespace App\Contracts;

use App\Models\Client;
use App\Models\Office;

/**
 * Resolve identidades da cadeia Integra para SITFIS (contratante → autor → contribuinte).
 *
 * @phpstan-type SitfisIdentities array{
 *     environment: \App\Enums\SerproEnvironment,
 *     contract: \App\Models\SerproContract,
 *     contractor_cnpj: string,
 *     author_identity: string,
 *     contributor_cnpj: string
 * }
 */
interface SitfisIdentityResolving
{
    /**
     * @return SitfisIdentities
     */
    public function resolve(Office $office, Client $client): array;
}
