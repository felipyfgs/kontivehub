<?php

namespace App\Services\MeiAutomation\Providers;

use App\Contracts\FiscalSourceAdapter;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\Enums\MeiProvider;

final readonly class SerproMeiProvider implements MeiOperationProvider
{
    public function __construct(
        private FiscalSourceAdapter $adapter,
    ) {}

    public function execute(FiscalAdapterRequest $request, string $operationKey): MeiProviderOutcome
    {
        return new MeiProviderOutcome(
            result: $this->adapter->execute($request),
            provider: MeiProvider::Serpro,
        );
    }
}
