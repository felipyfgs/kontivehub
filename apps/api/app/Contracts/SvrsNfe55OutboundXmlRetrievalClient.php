<?php

namespace App\Contracts;

use App\DTO\Outbound\SvrsNfceRetrievalRequest;
use App\DTO\Outbound\SvrsNfceRetrievalResult;

/**
 * Cliente de download pontual NF-e 55 via portal NFESSL (mesmos DTOs tipados do canal NFC-e).
 */
interface SvrsNfe55OutboundXmlRetrievalClient
{
    public function isAvailable(): bool;

    /**
     * @param  array{pfx: string, password: string}  $certificate
     */
    public function retrieve(SvrsNfceRetrievalRequest $request, array $certificate): SvrsNfceRetrievalResult;
}
