<?php

namespace App\Contracts;

use App\DTO\FgtsDigital\FgtsDigitalPortalRequest;
use App\DTO\FgtsDigital\FgtsDigitalPortalResult;

interface FgtsDigitalPortalClient
{
    public function execute(FgtsDigitalPortalRequest $request): FgtsDigitalPortalResult;
}
