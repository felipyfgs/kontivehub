<?php

namespace App\Services\Integra;

use App\Domain\Cnpj;
use App\Models\Client;
use App\Models\Establishment;
use RuntimeException;

/**
 * Resolve o NI completo do contribuinte a partir de estabelecimento persistido.
 * A raiz de oito caracteres nunca é enviada como identidade ao Integra.
 */
final class ContributorCnpjResolver
{
    public function resolve(Client $client): string
    {
        $establishment = Establishment::query()
            ->withoutGlobalScopes()
            ->where('office_id', $client->office_id)
            ->where('client_id', $client->id)
            ->where('is_active', true)
            ->orderByDesc('is_matrix')
            ->orderBy('id')
            ->first();

        $cnpj = is_string($establishment?->cnpj)
            ? Cnpj::tryParse($establishment->cnpj)
            : null;

        if ($cnpj === null) {
            throw new RuntimeException('CNPJ completo do contribuinte não encontrado.');
        }

        return $cnpj->value();
    }
}
