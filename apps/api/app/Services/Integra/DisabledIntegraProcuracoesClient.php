<?php

namespace App\Services\Integra;

use App\Contracts\IntegraProcuracoesClient;
use App\DTO\Serpro\ProcuracaoLookupRequest;
use App\DTO\Serpro\ProcuracaoLookupResult;

final class DisabledIntegraProcuracoesClient implements IntegraProcuracoesClient
{
    public function lookup(ProcuracaoLookupRequest $request): ProcuracaoLookupResult
    {
        return new ProcuracaoLookupResult(
            success: false,
            powers: [],
            errorCode: 'CAPABILITY_DISABLED',
            errorMessage: 'Consulta de procurações SERPRO desabilitada.',
            simulated: false,
        );
    }
}
