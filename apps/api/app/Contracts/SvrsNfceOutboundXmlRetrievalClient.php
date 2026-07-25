<?php

namespace App\Contracts;

use App\DTO\Outbound\SvrsNfceRetrievalRequest;
use App\DTO\Outbound\SvrsNfceRetrievalResult;

/**
 * Recuperação de nfeProc NFC-e 65 via portal SVRS (HTTP+mTLS).
 * HTML bruto não vaza para camadas acima da infraestrutura.
 */
interface SvrsNfceOutboundXmlRetrievalClient
{
    public function isAvailable(): bool;

    /**
     * @param  array{pfx: string, password: string}  $certificate  PFX somente em memória
     */
    public function retrieve(SvrsNfceRetrievalRequest $request, array $certificate): SvrsNfceRetrievalResult;
}
