<?php

namespace App\Contracts;

use App\DTO\Serpro\ProcuracaoLookupRequest;
use App\DTO\Serpro\ProcuracaoLookupResult;

interface IntegraProcuracoesClient
{
    public function lookup(ProcuracaoLookupRequest $request): ProcuracaoLookupResult;
}
