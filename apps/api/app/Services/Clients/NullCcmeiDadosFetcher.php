<?php

namespace App\Services\Clients;

use App\Models\Client;

/**
 * Default: CCMEI enrichment desligado / sem chamada Integra Contador no lookup.
 */
final class NullCcmeiDadosFetcher implements CcmeiDadosFetcher
{
    public function fetch(string $cnpj, ?Client $client = null): ?array
    {
        return null;
    }
}
