<?php

namespace App\Contracts;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\Enums\TaxInstallmentModality;

interface ParcelamentoSource
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     success: bool,
     *     simulated: bool,
     *     timeout_uncertain?: bool,
     *     error_code?: string,
     *     error_message?: string,
     *     body: array<string, mixed>
     * }
     */
    public function execute(
        TaxInstallmentModality $modality,
        string $operation,
        array $payload = [],
        ?FiscalAdapterRequest $request = null,
    ): array;
}
