<?php

namespace App\Services\Clients;

use App\Models\Client;

/**
 * Busca dados brutos/decodificados de DADOSCCMEI122 para enrichment cadastral.
 */
interface CcmeiDadosFetcher
{
    /**
     * @return array<string, mixed>|null
     */
    public function fetch(string $cnpj, ?Client $client = null): ?array;
}
