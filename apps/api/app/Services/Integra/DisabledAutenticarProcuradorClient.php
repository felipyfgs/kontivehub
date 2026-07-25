<?php

namespace App\Services\Integra;

use App\Contracts\AutenticarProcuradorClient;
use App\DTO\Serpro\ProcuradorAuthRequest;
use App\DTO\Serpro\ProcuradorAuthResult;
use App\Enums\TermoAuthorizationState;

final class DisabledAutenticarProcuradorClient implements AutenticarProcuradorClient
{
    public function authenticate(ProcuradorAuthRequest $request): ProcuradorAuthResult
    {
        return new ProcuradorAuthResult(
            success: false,
            errorCode: 'CAPABILITY_DISABLED',
            errorMessage: 'Autentica Procurador desabilitado.',
            authorizationState: TermoAuthorizationState::Rejected->value,
        );
    }
}
