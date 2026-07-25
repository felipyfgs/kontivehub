<?php

namespace App\Contracts;

use App\DTO\Cnpj\CnpjRegistrationLookupResult;

interface CnpjRegistrationLookup
{
    /**
     * Consulta cadastral sanitizada (Cartão CNPJ em JSON).
     * Pode incluir capital, CNAEs secundários, IEs e QSA com documento mascarado.
     * Nunca deve retornar CPF/CNPJ de sócio em claro nem payload bruto da API.
     *
     * @throws \RuntimeException falha sanitizada (indisponível, rate limit, 404, incompleto)
     */
    public function find(string $cnpj): CnpjRegistrationLookupResult;

    /**
     * Resolve proveniência a partir do cache sanitizado (sem segunda chamada externa).
     */
    public function getCached(string $cnpj): ?CnpjRegistrationLookupResult;
}
