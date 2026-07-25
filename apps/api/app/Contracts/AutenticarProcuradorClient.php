<?php

namespace App\Contracts;

use App\DTO\Serpro\ProcuradorAuthRequest;
use App\DTO\Serpro\ProcuradorAuthResult;

interface AutenticarProcuradorClient
{
    public function authenticate(ProcuradorAuthRequest $request): ProcuradorAuthResult;
}
