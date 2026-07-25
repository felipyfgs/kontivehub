<?php

namespace App\Services\MeiAutomation\Providers;

use App\DTO\Fiscal\FiscalAdapterRequest;

interface MeiOperationProvider
{
    public function execute(FiscalAdapterRequest $request, string $operationKey): MeiProviderOutcome;
}
