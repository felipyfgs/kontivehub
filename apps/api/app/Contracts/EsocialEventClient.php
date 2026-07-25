<?php

namespace App\Contracts;

use App\DTO\Esocial\EsocialFetchRequest;
use App\DTO\Esocial\EsocialFetchResult;

/**
 * Contrato estrito para eventos eSocial oficiais disponíveis (S-5003, S-5013, S-1299).
 *
 * Implementações MUST NOT:
 * - scrapear portais, Gov.br, CAPTCHA, cookies ou sessão humana;
 * - declarar guias/pagamentos/débitos do FGTS Digital sem API pública oficial.
 */
interface EsocialEventClient
{
    public function fetchEvents(EsocialFetchRequest $request): EsocialFetchResult;
}
