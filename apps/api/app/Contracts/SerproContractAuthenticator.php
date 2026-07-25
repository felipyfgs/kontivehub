<?php

namespace App\Contracts;

use App\DTO\Serpro\SerproAuthToken;
use App\Models\SerproContract;

/**
 * Obtém Bearer/JWT do contratante SERPRO (mTLS + OAuth2).
 * Material sensível só em memória; nunca retorna Consumer Secret ou PFX.
 */
interface SerproContractAuthenticator
{
    /**
     * Token válido para o contrato (cache + renovação coordenada).
     */
    public function authenticate(SerproContract $contract): SerproAuthToken;

    /**
     * Invalida cache de token do contrato (rotação/revogação).
     */
    public function invalidate(SerproContract $contract): void;
}
