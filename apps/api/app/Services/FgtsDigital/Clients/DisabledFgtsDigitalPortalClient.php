<?php

namespace App\Services\FgtsDigital\Clients;

use App\Contracts\FgtsDigitalPortalClient;
use App\DTO\FgtsDigital\FgtsDigitalPortalRequest;
use App\DTO\FgtsDigital\FgtsDigitalPortalResult;
use App\Services\FgtsDigital\Exceptions\FgtsDigitalException;

final class DisabledFgtsDigitalPortalClient implements FgtsDigitalPortalClient
{
    public function execute(FgtsDigitalPortalRequest $request): FgtsDigitalPortalResult
    {
        throw new FgtsDigitalException('Portal FGTS Digital desabilitado.', 'FGTS_DIGITAL_DISABLED', 503);
    }
}
