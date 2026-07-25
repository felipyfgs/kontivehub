<?php

namespace App\Services\FiscalMonitoring;

use App\Contracts\FiscalSourceAdapter;
use App\DTO\Fiscal\FiscalAdapterRequest;

/**
 * Registro de adapters por system/service/operation.
 * Módulos filhos registram em AppServiceProvider::boot.
 */
final class FiscalAdapterRegistry
{
    /** @var list<FiscalSourceAdapter> */
    private array $adapters = [];

    public function register(FiscalSourceAdapter $adapter): void
    {
        $this->adapters[] = $adapter;
    }

    public function resolve(FiscalAdapterRequest $request): FiscalSourceAdapter
    {
        foreach ($this->adapters as $adapter) {
            if (
                strcasecmp($adapter->systemCode(), $request->systemCode) === 0
                && strcasecmp($adapter->serviceCode(), $request->serviceCode) === 0
                && strcasecmp($adapter->operationCode(), $request->operationCode) === 0
                && $adapter->supports($request)
            ) {
                return $adapter;
            }
        }

        // Fallback: qualquer adapter que suporte o request
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($request)) {
                return $adapter;
            }
        }

        return new NullFiscalSourceAdapter(
            $request->systemCode,
            $request->serviceCode,
            $request->operationCode,
        );
    }

    /**
     * @return list<FiscalSourceAdapter>
     */
    public function all(): array
    {
        return $this->adapters;
    }
}
