<?php

namespace App\Services\Clients;

use App\Models\Client;
use RuntimeException;

/**
 * Conflito de CNPJ completo já cadastrado (1 cliente = 1 estabelecimento).
 * O nome histórico "Root" permanece por compatibilidade de imports; a mensagem
 * e o uso referem-se ao CNPJ completo do estabelecimento.
 */
final class ClientRootConflictException extends RuntimeException
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
