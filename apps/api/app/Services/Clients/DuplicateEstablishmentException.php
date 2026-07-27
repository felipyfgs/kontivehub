<?php

namespace App\Services\Clients;

use App\Models\Client;
use RuntimeException;

/** Conflito de CNPJ completo já cadastrado como estabelecimento do tenant. */
final class DuplicateEstablishmentException extends RuntimeException
{
    /**
     * @param  Client|null  $existingClient  Cliente ativo do escritório quando acessível;
     *                                       null para soft-delete/corrida (409 genérico, sem dados externos).
     */
    public function __construct(public readonly ?Client $existingClient = null)
    {
        parent::__construct(
            $existingClient !== null
                ? 'Já existe cliente com este CNPJ neste escritório.'
                : 'CNPJ já cadastrado neste escritório.'
        );
    }
}
